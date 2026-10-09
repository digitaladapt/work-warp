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
    ) {
    }

    /**
     * No exit code yet, by definition — asking a running container how it ended
     * would invent an answer.
     */
    public static function running(): self
    {
        return new self(true, null);
    }

    public static function stopped(?int $exitCode): self
    {
        return new self(false, $exitCode);
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

        if (true === ($state['Running'] ?? false)) {
            return self::running();
        }

        $exitCode = $state['ExitCode'] ?? null;

        return self::stopped(\is_int($exitCode) ? $exitCode : null);
    }
}
