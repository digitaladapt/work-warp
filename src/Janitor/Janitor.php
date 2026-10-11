<?php

declare(strict_types=1);

namespace App\Janitor;

use App\Docker\ContainerSummary;
use App\Docker\DockerApi;
use App\Docker\DockerException;
use App\Docker\Labels;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * The sweep: what stops containers that outlived their useful life, even when
 * the broker that created them is dead (DOCKER-ACCESS.md §10 step 3).
 *
 * The whole design is one sentence from PLAN.md §8 — "two ages, not one" —
 * and the honest reading of it is *one clock per container, decided at create
 * time*: `ww.ttl` is stamped by `ContainerSpec` as the command's own timeout
 * plus the grace period, so the label already knows how long that container
 * was meant to live. The sweep compares each container's age against its own
 * label and takes exactly one action:
 *
 * | Condition                       | Action                        |
 * |---------------------------------|-------------------------------|
 * | Running past its `ww.ttl`       | `POST /stop` — SIGTERM, honour |
 * |                                 | the container's StopTimeout,  |
 * |                                 | then SIGKILL (the daemon does |
 * |                                 | it all; verified in stop.go)  |
 * | Not running past its `ww.ttl`   | `DELETE ?force=1&v=1` — the   |
 * |                                 | second row is the important   |
 * |                                 | one: anonymous volumes and    |
 * |                                 | dangling state are what fill  |
 * |                                 | a disk, not running processes |
 *
 * **Why the runner cannot lose a race to this.** A live `ExecRunner` holds a
 * container for at most its timeout plus the kill grace (ten seconds one poll
 * at a time), while `ww.ttl` is that same timeout plus `ttlGrace` — five
 * minutes by default, and the design's floor is that grace being larger than
 * the kill grace. So a container *past its TTL* has, by construction, no
 * live consumer: either the command overran (the runner is already killing
 * it) or the broker died. The sweep cannot steal a container from a focused
 * runner, and does not have to ask one for permission to be sure.
 *
 * **Why one action per pass.** A container this pass stopped is not also
 * removed in the same pass; the next sweep finds it stopped, still past its
 * TTL, and removes it. This is slower by one interval and better in every
 * other way: every report line says exactly what happened, a stop that fails
 * does not silently take a removal with it, and "stopped but not yet removed"
 * is a state a human can inspect.
 *
 * **Why the ledger.** §8 says kills are written to the session's ledger
 * (§6.2) — "reaping the agent cannot observe turns a clean system into a
 * confusing one". The write goes through the same disposable-container path
 * a command uses (`LedgerWriter`), and it is best-effort: a ledger line that
 * cannot be written is a log line, never a reason to leave a container
 * running.
 *
 * **What it is not.** Bookkeeping, not security. The session can label
 * whatever it likes because it owns the access it was given; enforcement of
 * labels happens at the broker, at create time, which is already true
 * (`ContainerSpec` stamps every label, and a caller has no field for any of
 * them). This class only decides, from the labels that are there, what no
 * longer deserves to exist.
 */
final class Janitor
{
    public function __construct(
        private readonly DockerApi $docker,
        private readonly LedgerWriter $ledger,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {
    }

    public function sweep(): ReapReport
    {
        $now = $this->secondsNow();
        /** @var list<ReapedContainer> $reaped */
        $reaped = [];
        /** @var list<ReapFailure> $failed */
        $failed = [];

        $containers = $this->docker->listContainers();

        foreach ($containers as $container) {
            $ttl = $this->ttlOf($container);

            if (null === $ttl) {
                // No readable TTL means no clock to judge it by. Leaving it
                // alone is the only honest choice: a container with a
                // malformed label is a bug to see, not a licence to guess.
                $this->logger->warning('janitor: container {container} carries no usable ww.ttl label; leaving it alone', [
                    'container' => $container->name,
                ]);

                continue;
            }

            $age = (int) ($now - $container->createdAt);

            if ($age < $ttl) {
                continue;
            }

            $action = $container->running ? ReapAction::Stopped : ReapAction::Removed;

            try {
                if (ReapAction::Stopped === $action) {
                    $this->docker->stopContainer($container->id);
                } else {
                    // `v=1`: remove anonymous volumes with the container.
                    // Deleting a session's *named* workspace is not in this
                    // vocabulary on purpose — that is `DELETE /v1/sessions`
                    // territory, and a sweep that could do it would be a
                    // sweep that could delete someone's work.
                    $this->docker->removeContainer($container->id, removeAnonymousVolumes: true);
                }
            } catch (DockerException $e) {
                $this->logger->warning('janitor: could not {action} container {container}: {message}', [
                    'action' => $action->value,
                    'container' => $container->name,
                    'message' => $e->getMessage(),
                ]);

                $failed[] = new ReapFailure($container->name, $e->getMessage());

                continue;
            }

            $reaped[] = new ReapedContainer($container->name, $this->sessionOf($container), $action, $age, $ttl);

            $this->logger->info('janitor: {action} container {container} (age {age}s, ttl {ttl}s)', [
                'action' => $action->value,
                'container' => $container->name,
                'age' => $age,
                'ttl' => $ttl,
            ]);

            $this->ledger->recordReap($container->name, $this->sessionOf($container), $action, $age, $ttl);
        }

        return new ReapReport(\count($containers), $reaped, $failed);
    }

    /**
     * The container's own `ww.ttl`, in seconds, or null when it has none that
     * can be trusted.
     *
     * `\z` rather than `$`: `$` in PCRE also matches before a trailing
     * newline, so `/^\d+$/` would accept "300\n" as a TTL — the same trap the
     * request vocabulary already records (ExecRequest's patterns).
     */
    private function ttlOf(ContainerSummary $container): ?int
    {
        $raw = $container->labels[Labels::LABEL_TTL] ?? null;

        if (null === $raw || 1 !== preg_match('/^\d+\z/', $raw)) {
            return null;
        }

        $ttl = (int) $raw;

        return $ttl > 0 ? $ttl : null;
    }

    private function sessionOf(ContainerSummary $container): ?string
    {
        $session = $container->labels[Labels::LABEL_SESSION] ?? null;

        return \is_string($session) && '' !== $session ? $session : null;
    }

    /**
     * A float deadline against the injected clock, for the same reason the
     * runner and the slot pool do it this way: PHP's relative date format
     * silently ignores fractional units, and a number has no such trap.
     */
    private function secondsNow(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
