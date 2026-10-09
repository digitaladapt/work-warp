<?php

declare(strict_types=1);

namespace App\Exec;

use App\Docker\ContainerSpec;
use App\Docker\DockerApi;
use App\Docker\DockerException;
use App\Docker\Labels;
use App\Docker\Limits;
use App\Session\NoSuchSession;
use App\Session\SessionName;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * One command, from slot to result.
 *
 * The whole lifecycle in one place, in the order the design fixes it: check
 * the session exists, hold a slot for the whole run, ensure the session's
 * network only if the command asked to reach it, create from `ContainerSpec`,
 * start, wait against a deadline the broker owns, kill at expiry, read the
 * capped logs, and remove the container before the slot goes back.
 *
 * Two choices are deliberate and worth stating, because they are the kind of
 * detail that quietly rots:
 *
 * - **Polling, not a blocking wait.** `POST /containers/{id}/wait` would hold
 *   a broker request open for the whole length of a command — hours, for a
 *   test suite — and would hand the timeout to whatever client happened to
 *   be in the middle. Polling against the injected clock keeps the deadline
 *   the broker's own and keeps this loop testable without a daemon and
 *   without real seconds.
 * - **Kill is SIGKILL at the deadline, with no graceful stop first.** The
 *   design's phrase is a "hard clock" (§8): a command that needed more time
 *   than the caller granted is over, and a stop-and-wait dance would trade
 *   that guarantee for politeness. The exit code that comes back is the
 *   truth of what happened — 137 — not a rewritten zero.
 *
 * If the broker dies between `createContainer` and `removeContainer`, the
 * container overruns; that is what the `ww.ttl` label it carries is for — the
 * janitor (step 3) sweeps what the broker did not. The slot itself cannot
 * leak: it is a file description, so the kernel frees it when this process
 * ends (DOCKER-ACCESS.md §6b).
 */
final class ExecRunner
{
    /**
     * The gap between two inspections. Small enough that a short command is
     * not perceptibly slowed by granularity, large enough that a long one is
     * not a busy meter against the daemon.
     */
    public const float POLL_INTERVAL_SECONDS = 0.1;

    /**
     * How long the broker keeps looking after it has sent SIGKILL before it
     * gives up and reports the exit code as unknown. A killed container
     * normally reads as stopped on the first inspection; this is the bound
     * for when it does not, so "the kill did not take yet" can never become
     * "the broker hangs".
     */
    private const float KILL_GRACE_SECONDS = 10.0;

    public function __construct(
        private readonly DockerApi $docker,
        private readonly Slots $slots,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
        private readonly string $image,
        private readonly int $ttlGrace,
    ) {
    }

    public function run(SessionName $session, ExecRequest $request): ExecResult
    {
        // Capability first: stdin cannot be delivered by this build, and that
        // is true before anything about this session matters. Refused here so
        // no Docker call is made for a command that could not be run whole.
        if (null !== $request->stdin) {
            throw StdinUnsupported::because();
        }

        $workspace = $session->workspaceVolume();

        // The session is not created on the way: a command aimed at a
        // mistyped name must be a refusal, not a new durable object.
        if (!$this->docker->volumeExists($workspace)) {
            throw NoSuchSession::named($session->value);
        }

        // Refuses rather than queues (DOCKER-ACCESS.md §6b). Waiting stays
        // opt-in at the `Slots` layer; this endpoint does not use it.
        $slot = $this->slots->reserve();
        $containerId = null;
        $limits = $this->limits();

        try {
            if (NetworkMode::Session === $request->network) {
                $this->ensureSessionNetwork($session);
            }

            $containerId = $this->docker->createContainer(
                $session->containerName(bin2hex(random_bytes(5))),
                ContainerSpec::payload($this->image, $session, $request, $workspace, $limits),
            );
            $this->docker->startContainer($containerId);

            [$exitCode, $timedOut] = $this->awaitStop($containerId, $this->secondsNow() + $request->timeout);

            $logs = $this->docker->containerLogs($containerId, $limits->outputByteCap);

            return new ExecResult($exitCode, $logs->stdout, $logs->stderr, $logs->truncated, $timedOut);
        } finally {
            if (null !== $containerId) {
                $this->remove($containerId);
            }

            $slot->release();
        }
    }

    /**
     * Wait for the command to end by itself, and kill it when the deadline
     * passes first.
     *
     * @return array{int|null, bool} the exit code, and whether time ran out
     */
    private function awaitStop(string $containerId, float $deadline): array
    {
        while (true) {
            $status = $this->docker->inspectContainer($containerId);

            if (!$status->running) {
                return [$status->exitCode, false];
            }

            if ($this->secondsNow() >= $deadline) {
                return $this->killAndAwaitStop($containerId);
            }

            $this->clock->sleep(self::POLL_INTERVAL_SECONDS);
        }
    }

    /**
     * @return array{int|null, bool}
     */
    private function killAndAwaitStop(string $containerId): array
    {
        $this->docker->killContainer($containerId);

        $graceEnds = $this->secondsNow() + self::KILL_GRACE_SECONDS;

        while ($this->secondsNow() < $graceEnds) {
            $status = $this->docker->inspectContainer($containerId);

            if (!$status->running) {
                return [$status->exitCode, true];
            }

            $this->clock->sleep(self::POLL_INTERVAL_SECONDS);
        }

        // The signal was sent and the state has not settled inside the
        // budget. Report no exit code rather than inventing one; the
        // container is removed by the caller's `finally` regardless.
        return [null, true];
    }

    /**
     * A session's network exists when a command first needs to reach it.
     *
     * Lazy on purpose: a bridge network per session is cheap to create but
     * wasteful to keep for the sessions that never ask to reach anything —
     * the daemon's default address pool is finite. The name comes from
     * `SessionName` and the labels from `Labels`, so this can only ever
     * create the session's own network, never an arbitrary one.
     */
    private function ensureSessionNetwork(SessionName $session): void
    {
        $network = $session->network();

        if ($this->docker->networkExists($network)) {
            return;
        }

        $this->docker->createNetwork($network, Labels::network($session));
    }

    /**
     * Best-effort removal: the container must not outlive the command, but a
     * failure to remove must never mask the command's own outcome. The
     * request already has a result or an error; a cleanup problem is a log
     * line and a container the janitor will sweep via its `ww.ttl` label, not
     * a different answer to the caller.
     */
    private function remove(string $containerId): void
    {
        try {
            $this->docker->removeContainer($containerId);
        } catch (DockerException $e) {
            $this->logger->warning('could not remove command container {container}: {message}', [
                'container' => $containerId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The one limits field that is configuration today (`WW_TTL_GRACE`); the
     * rest is policy defaulted in `Limits` itself.
     */
    private function limits(): Limits
    {
        return new Limits(ttlGrace: $this->ttlGrace);
    }

    /**
     * Seconds since the epoch, fraction included, for the same reason `Slots`
     * does its waiting arithmetic this way: PHP's relative date format
     * silently ignores fractional units, so a deadline built from
     * `modify('+1.000 seconds')` never arrives. A number has no such trap.
     */
    private function secondsNow(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
