<?php

declare(strict_types=1);

namespace App\Docker;

/**
 * What `GET /containers/{id}/json` says about a container, reduced to the two
 * facts the broker acts on.
 *
 * The reduction is the point: a typed value cannot accidentally grow into a
 * passthrough for the daemon's inspection payload, and the poll loop can treat
 * "still running" and "stopped with this exit code" as the only two states that
 * exist.
 */
final class ContainerStatus
{
    private function __construct(
        public readonly bool $running,
        public readonly ?int $exitCode,
        public readonly ?string $health,
    ) {
    }

    /**
     * No exit code yet, by definition — asking a running container how it ended
     * would invent an answer.
     *
     * `$health` is the daemon's health-check state (`starting`, `healthy`,
     * `unhealthy`) when the container declares a health check, and null when
     * it does not. Read from `State.Health.Status`; the stack runner is the
     * caller that needs it, because `healthy` is a dependency condition.
     */
    public static function running(?string $health = null): self
    {
        return new self(true, null, $health);
    }

    public static function stopped(?int $exitCode, ?string $health = null): self
    {
        return new self(false, $exitCode, $health);
    }

    /**
     * @param array<string, mixed> $container the decoded `GET /containers/{id}/json` body
     */
    public static function fromApiResponse(array $container): self
    {
        $state = $container['State'] ?? null;

        if (!\is_array($state)) {
            throw DockerRefused::because('inspecting the container returned no State block');
        }

        $health = $state['Health'] ?? null;
        $healthStatus = \is_array($health) && \is_string($health['Status'] ?? null) ? $health['Status'] : null;

        if (true === ($state['Running'] ?? false)) {
            return self::running($healthStatus);
        }

        $exitCode = $state['ExitCode'] ?? null;

        return self::stopped(\is_int($exitCode) ? $exitCode : null, $healthStatus);
    }
}
