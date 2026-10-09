<?php

declare(strict_types=1);

namespace App\Docker;

/**
 * The Docker daemon was reached, and refused.
 *
 * The message carries the daemon's own words where it has any — "No such
 * image", "No such container" — because a refusal without the daemon's reason
 * is a support ticket. This maps to `502`: the broker worked, its upstream
 * said no.
 */
final class DockerRefused extends DockerException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
