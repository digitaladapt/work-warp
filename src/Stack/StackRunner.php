<?php

declare(strict_types=1);

namespace App\Stack;

use App\Docker\ContainerSummary;
use App\Docker\DockerApi;
use App\Docker\DockerException;
use App\Docker\Labels;
use App\Docker\Limits;
use App\Exec\Slots;
use App\Session\NoSuchSession;
use App\Session\SessionName;
use App\Session\SessionNetwork;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * The stack harness: bring a stack up, take it down, read its logs.
 *
 * This is the answer to the step-4b question — "a real `docker compose up`
 * test against a real stack" when the session deliberately has no daemon
 * access. The session cannot run `docker compose` (it is a Docker API client,
 * and the API is unreachable from a session by design); the broker is the only
 * thing that can, and the broker must not run a caller-supplied compose file
 * (a compose file can say `privileged: true`, `network_mode: host`,
 * `volumes: [/:/host]` — the exact hostile-body class the whole design
 * excludes, and validating a file would be *filtering*, which this project has
 * already ruled out once). So the broker renders and runs the stack itself,
 * from `StackRequest` — compose at the vocabulary altitude. `StackPlan` is the
 * resolved start order; `StackContainerSpec` is the create payload; this class
 * is the bring-up, teardown and log assembly around them.
 *
 * Three decisions that are this class's own:
 *
 * - **`up` holds a slot; `down` and `logs` do not.** A bring-up runs
 *   containers, so it is work and it contends for the workspace's budget like
 *   a command or a build (§6b). A teardown *reduces* load and a log read adds
 *   none — refusing either because a budget is full would be the worst of
 *   both: the caller cannot free resources precisely when the machine is
 *   busiest.
 * - **`up` is ensure-with-recreation.** For each service, an existing
 *   container with the deterministic name (`ww-<session>-<service>-1`) is
 *   removed and recreated. That is what makes the loop the harness exists for
 *   work at all — build, up, test, edit, up again — and it is stated rather
 *   than left to a name-conflict error from the daemon.
 * - **Images are pulled when absent, never when present.** `imageExists` is
 *   asked first; a present image is used as-is (fast, and it is what makes
 *   `build` then `up` work: the scratch image is already there). Only a
 *   missing image costs a pull, and the pull's *outcome* is asked of the
 *   daemon afterwards — never read from its progress text.
 *
 * The bring-up is bounded by the request's timeout — a single deadline shared
 * by every wait (dependency completion, health) — and a budget that expires is
 * a *result* (`timedOut: true`), not a 5xx, for the same reason a killed
 * command or a cut build is: the broker worked and the caller needs to tell
 * "too slow" apart from "broken". A failure mid-way stops the bring-up; what
 * was started stays up, and the caller's tool for going back to nothing is
 * `down`.
 *
 * The one listing: `up` and `down` each take exactly one `listContainers()`
 * (and `down` one `listVolumes()`), and the bring-up tracks the ids it creates
 * as it goes. Ids it created are facts; re-listing to "find" a container this
 * same loop just started would be neither cheaper nor more correct.
 */
final class StackRunner
{
    /**
     * How long between two inspections of a dependency's container. The same
     * granularity as `ExecRunner`'s poll, for the same reason: a database that
     * becomes healthy in a second should not wait a second and a half to be
     * noticed, and a five-minute wait should not be a busy meter.
     */
    public const float POLL_INTERVAL_SECONDS = 0.25;

    /**
     * How long a single image pull may take. Fixed policy rather than per-
     * request input: a pull is a means, not the work itself, and a caller that
     * needs a slow registry allowance raises the *stack* timeout, which is the
     * budget that actually describes the bring-up.
     */
    private const float PULL_TIMEOUT_SECONDS = 600.0;

    public function __construct(
        private readonly DockerApi $docker,
        private readonly Slots $slots,
        private readonly SessionNetwork $networks,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {
    }

    public function up(SessionName $session, StackRequest $request): UpResult
    {
        if (!$this->docker->volumeExists($session->workspaceVolume())) {
            throw NoSuchSession::named($session->value);
        }

        // The plan is built before anything is created: every ordering
        // refusal (unknown dependency, a `healthy` on a service with no
        // health command, a cycle) happens while the stack is still a value.
        $plan = StackPlan::for($request);

        $slot = $this->slots->reserve();
        $deadline = $this->secondsNow() + $request->timeout;
        $limits = new Limits();
        $network = $session->network();

        try {
            $this->networks->ensure($session);

            [$containerIds, $knownIds] = $this->existingContainers($session);

            /** @var list<StackServiceState> $states */
            $states = [];

            foreach ($plan->steps as [$service, $dependencies]) {
                foreach ($dependencies as [$dependency, $condition]) {
                    $failure = $this->waitFor($knownIds, $dependency, $condition, $deadline);

                    if (null !== $failure) {
                        return new UpResult($states, ['service' => $service->name, 'reason' => $failure], $this->expired($deadline));
                    }
                }

                if ($this->expired($deadline)) {
                    return new UpResult($states, ['service' => $service->name, 'reason' => 'the bring-up budget expired before this service was created'], true);
                }

                try {
                    $id = $this->bringUp($session, $service, $network, $limits, $request->timeout, $containerIds);
                } catch (DockerException $e) {
                    $this->logger->warning('stack: could not bring up {service} for session {session}: {message}', [
                        'service' => $service->name,
                        'session' => $session->value,
                        'message' => $e->getMessage(),
                    ]);

                    return new UpResult($states, ['service' => $service->name, 'reason' => $e->getMessage()], $this->expired($deadline));
                }

                $knownIds[$service->name] = $id;

                $status = $this->docker->inspectContainer($id);
                $states[] = new StackServiceState($service->name, $session->stackContainerName($service->name), $service->image, $status->running);

                $this->logger->info('stack: {service} is up for session {session} (container {container})', [
                    'service' => $service->name,
                    'session' => $session->value,
                    'container' => $session->stackContainerName($service->name),
                ]);
            }

            return new UpResult($states, null, false);
        } finally {
            $slot->release();
        }
    }

    public function down(SessionName $session, StackRequest $request): DownResult
    {
        if (!$this->docker->volumeExists($session->workspaceVolume())) {
            throw NoSuchSession::named($session->value);
        }

        $containers = [];
        $volumes = [];
        $failures = [];

        foreach ($this->docker->listContainers() as $container) {
            if (!$this->isStackContainerOf($container, $session)) {
                continue;
            }

            try {
                // Force-remove: a teardown stops and removes in one call, and
                // SIGKILL is right here — this is the harness saying "done",
                // not an operator asking politely.
                $this->docker->removeContainer($container->id, removeAnonymousVolumes: true);
                $containers[] = $container->name;
            } catch (DockerException $e) {
                $failures[] = ['name' => $container->name, 'reason' => $e->getMessage()];
            }
        }

        if (!$request->keepVolumes) {
            foreach ($this->docker->listVolumes() as $volume) {
                if (!$this->isStackVolumeOf($volume->name, $volume->labels, $session)) {
                    continue;
                }

                try {
                    $this->docker->removeVolume($volume->name);
                    $volumes[] = $volume->name;
                } catch (DockerException $e) {
                    $failures[] = ['name' => $volume->name, 'reason' => $e->getMessage()];
                }
            }
        }

        return new DownResult($containers, $volumes, $failures);
    }

    public function logs(SessionName $session, int $maxBytes): LogsResult
    {
        if (!$this->docker->volumeExists($session->workspaceVolume())) {
            throw NoSuchSession::named($session->value);
        }

        $stack = array_values(array_filter(
            $this->docker->listContainers(),
            fn (ContainerSummary $container): bool => $this->isStackContainerOf($container, $session),
        ));

        usort($stack, static fn (ContainerSummary $a, ContainerSummary $b): int => strcmp($a->name, $b->name));

        $output = '';
        $truncated = false;

        foreach ($stack as $container) {
            $service = $container->labels[Labels::LABEL_SERVICE] ?? $container->name;
            $header = \sprintf("==> %s <==\n", $service);
            $room = $maxBytes - \strlen($output) - \strlen($header);

            if ($room <= 0) {
                // The budget is spent; the remaining services are named in
                // the truncation notice rather than silently missing.
                $truncated = true;
                $output .= \sprintf("==> %s (output budget spent; not read) <==\n", $service);

                continue;
            }

            $logs = $this->docker->containerLogs($container->id, $room);

            $output .= $header;
            $output .= $logs->stdout;

            if ('' !== $logs->stderr) {
                $output .= "-- stderr --\n".$logs->stderr;
            }

            if ($logs->truncated) {
                $truncated = true;
            }
        }

        return new LogsResult($output, $truncated);
    }

    /**
     * Bring one service up and return its container's id: ensure its volumes,
     * ensure its image, recreate its container, start it.
     *
     * @param array<string, string> $containerIds container name → id, from the one listing taken before the loop
     */
    private function bringUp(
        SessionName $session,
        StackService $service,
        string $network,
        Limits $limits,
        int $timeout,
        array $containerIds,
    ): string {
        foreach ($service->mounts as $mount) {
            $name = $session->stackVolume($mount->source->value);

            if (!$this->docker->volumeExists($name)) {
                $this->docker->createVolume($name, Labels::stackVolume($session));
            }
        }

        if (!$this->docker->imageExists($service->image)) {
            $pull = $this->docker->pullImage($service->image, self::PULL_TIMEOUT_SECONDS, $limits->outputByteCap);

            // The outcome is asked, never parsed: a pull that streamed no
            // error line but produced no image is still a failure.
            if (!$this->docker->imageExists($service->image)) {
                throw new StackPullFailed(\sprintf('service "%s": pulling %s did not produce the image%s', $service->name, $service->image, '' === trim($pull->output) ? '' : ': '.trim($pull->output)));
            }
        }

        $name = $session->stackContainerName($service->name);

        // Ensure-with-recreation: a container under this deterministic name is
        // the previous run of this service, and `up` means "make it so now".
        if (isset($containerIds[$name])) {
            $this->docker->removeContainer($containerIds[$name], removeAnonymousVolumes: true);
        }

        $id = $this->docker->createContainer(
            $name,
            StackContainerSpec::payload($service, $session, $network, $limits, $timeout + $limits->ttlGrace),
        );
        $this->docker->startContainer($id);

        return $id;
    }

    /**
     * Wait for a dependency's condition to hold, or explain why it cannot.
     *
     * Returns null when satisfied, and the reason as a string when not — the
     * shape the caller needs, because "the reason" becomes the `failure` in
     * the result and a second exception type for it would be ceremony.
     *
     * The id comes from the bring-up's own tracking (`$knownIds`): the
     * dependency was created earlier in this same loop, so its id is a fact
     * this class already holds. A dependency with no known id is a plan bug,
     * not a race, and is reported as one.
     *
     * @param array<string, string> $knownIds service name → container id
     */
    private function waitFor(array $knownIds, StackService $dependency, ServiceCondition $condition, float $deadline): ?string
    {
        $id = $knownIds[$dependency->name] ?? null;

        if (null === $id) {
            return \sprintf('dependency "%s" has no started container (it should have been created earlier in this bring-up)', $dependency->name);
        }

        return match ($condition) {
            ServiceCondition::Started => null,
            ServiceCondition::Completed => $this->waitForCompletion($dependency, $id, $deadline),
            ServiceCondition::Healthy => $this->waitForHealth($dependency, $id, $deadline),
        };
    }

    private function waitForCompletion(StackService $dependency, string $id, float $deadline): ?string
    {
        while (true) {
            $status = $this->docker->inspectContainer($id);

            if (!$status->running) {
                if (0 === $status->exitCode) {
                    return null;
                }

                return \sprintf(
                    'dependency "%s" had to complete successfully, but it exited with code %s',
                    $dependency->name,
                    null === $status->exitCode ? 'unknown' : (string) $status->exitCode,
                );
            }

            if ($this->expired($deadline)) {
                return \sprintf('dependency "%s" did not complete before the bring-up budget expired', $dependency->name);
            }

            $this->clock->sleep(self::POLL_INTERVAL_SECONDS);
        }
    }

    private function waitForHealth(StackService $dependency, string $id, float $deadline): ?string
    {
        while (true) {
            $status = $this->docker->inspectContainer($id);

            if (!$status->running) {
                return \sprintf('dependency "%s" exited before it ever reported healthy', $dependency->name);
            }

            if ('healthy' === $status->health) {
                return null;
            }

            if ('unhealthy' === $status->health) {
                return \sprintf('dependency "%s" reported unhealthy', $dependency->name);
            }

            if ($this->expired($deadline)) {
                return \sprintf('dependency "%s" was never healthy before the bring-up budget expired', $dependency->name);
            }

            $this->clock->sleep(self::POLL_INTERVAL_SECONDS);
        }
    }

    /**
     * One listing, two maps: the ids of this session's stack containers, by
     * container name (for the recreate lookup) and by service name (for the
     * dependency waits).
     *
     * @return array{array<string, string>, array<string, string>} container name → id, service name → id
     */
    private function existingContainers(SessionName $session): array
    {
        $containerIds = [];
        $knownIds = [];

        foreach ($this->docker->listContainers() as $container) {
            if (!$this->isStackContainerOf($container, $session)) {
                continue;
            }

            $containerIds[$container->name] = $container->id;

            $service = $container->labels[Labels::LABEL_SERVICE] ?? null;

            if (null !== $service) {
                $knownIds[$service] = $container->id;
            }
        }

        return [$containerIds, $knownIds];
    }

    private function isStackContainerOf(ContainerSummary $container, SessionName $session): bool
    {
        return ($container->labels[Labels::LABEL_SESSION] ?? null) === $session->value
            && ($container->labels[Labels::LABEL_KIND] ?? null) === Labels::KIND_STACK;
    }

    /**
     * @param array<string, string> $labels
     */
    private function isStackVolumeOf(string $name, array $labels, SessionName $session): bool
    {
        return ($labels[Labels::LABEL_SESSION] ?? null) === $session->value
            && ($labels[Labels::LABEL_KIND] ?? null) === Labels::KIND_STACK_VOLUME
            && str_starts_with($name, 'ww-stk-'.$session->value.'-');
    }

    /**
     * Seconds since the epoch, fraction included — a number, not a
     * `DateTimeImmutable`, for the reason `Slots` records: PHP's relative date
     * format silently ignores fractional units, so a deadline built with
     * `modify('+0.25 seconds')` would never arrive.
     */
    private function secondsNow(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }

    private function expired(float $deadline): bool
    {
        return $this->secondsNow() >= $deadline;
    }
}
