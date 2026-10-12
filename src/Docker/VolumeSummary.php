<?php

declare(strict_types=1);

namespace App\Docker;

/**
 * A volume as `GET /volumes` describes it, reduced to the two facts a cleanup
 * acts on: its name, and the labels that say whose it is.
 *
 * The same discipline as `ContainerSummary`: ownership is decided by labels
 * and never by the name, and the reduction means a cleanup path cannot grow
 * into a passthrough for the daemon's volume payload.
 */
final class VolumeSummary
{
    /**
     * @param array<string, string> $labels
     */
    private function __construct(
        public readonly string $name,
        public readonly array $labels,
    ) {
    }

    /**
     * @param array<string, mixed> $volume one element of the decoded `GET /volumes` body
     */
    public static function fromApiVolume(array $volume): self
    {
        $name = $volume['Name'] ?? null;

        if (!\is_string($name) || '' === $name) {
            throw DockerRefused::because('the daemon listed a volume with no name');
        }

        $labels = $volume['Labels'] ?? null;

        if (null === $labels) {
            // The daemon answers `null` for a volume with no labels at all;
            // that is an empty set, not a malformed answer.
            $labels = [];
        }

        if (!\is_array($labels)) {
            throw DockerRefused::because(\sprintf('the daemon listed the volume %s with labels that are not a map', $name));
        }

        $mapped = [];

        foreach ($labels as $label => $value) {
            if (!\is_string($label) || !\is_string($value)) {
                throw DockerRefused::because(\sprintf('the daemon listed the volume %s with a label that is not a string pair', $name));
            }

            $mapped[$label] = $value;
        }

        return new self($name, $mapped);
    }
}
