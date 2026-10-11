<?php

declare(strict_types=1);

namespace App\Docker;

/**
 * A container as `GET /containers/json` describes it, reduced to what a sweep
 * acts on.
 *
 * The reduction is the same discipline as `ContainerStatus`'s: a typed value
 * cannot grow into a passthrough for the daemon's summary payload, and the
 * janitor can treat "running" and "not running" as the only two states that
 * exist. `Created` is seconds since the epoch — the same unit `ww.ttl` is in —
 * so age is arithmetic and not a date-library problem.
 */
final class ContainerSummary
{
    /**
     * @param array<string, string> $labels
     */
    private function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly bool $running,
        public readonly int $createdAt,
        public readonly array $labels,
    ) {
    }

    /**
     * @param array<string, mixed> $summary one element of the decoded `GET /containers/json` body
     */
    public static function fromApiSummary(array $summary): self
    {
        $id = $summary['Id'] ?? null;

        if (!\is_string($id) || '' === $id) {
            throw DockerRefused::because('the daemon listed a container with no id');
        }

        $names = $summary['Names'] ?? null;
        $name = \is_array($names) ? ($names[0] ?? null) : null;

        if (!\is_string($name) || '' === $name) {
            throw DockerRefused::because(\sprintf('the daemon listed the container %s without a name', $id));
        }

        $state = $summary['State'] ?? null;

        if (!\is_string($state)) {
            throw DockerRefused::because(\sprintf('the daemon listed the container %s without a state', $id));
        }

        $created = $summary['Created'] ?? null;

        if (!\is_int($created)) {
            throw DockerRefused::because(\sprintf('the daemon listed the container %s without a creation time', $id));
        }

        $labels = $summary['Labels'] ?? null;

        if (null === $labels) {
            // The daemon answers `null` for a container with no labels at
            // all; that is an empty set, not a malformed answer.
            $labels = [];
        }

        if (!\is_array($labels)) {
            throw DockerRefused::because(\sprintf('the daemon listed the container %s with labels that are not a map', $id));
        }

        $mapped = [];

        foreach ($labels as $label => $value) {
            if (!\is_string($label) || !\is_string($value)) {
                throw DockerRefused::because(\sprintf('the daemon listed the container %s with a label that is not a string pair', $id));
            }

            $mapped[$label] = $value;
        }

        // Docker prefixes each name with a slash; the broker's vocabulary
        // never does, and `ww.session` remains the authority either way.
        return new self($id, ltrim($name, '/'), 'running' === $state, $created, $mapped);
    }
}
