<?php

declare(strict_types=1);

namespace App\Docker;

/**
 * The Docker daemon could not be reached — nothing was asked, and nothing was
 * refused.
 *
 * The usual causes are a missing `WW_DOCKER_HOST`, a proxy that is down, or a
 * network the broker should be on and is not. All three are configuration or
 * infrastructure, which is why this maps to `503` and never to a `4xx`: the
 * request was fine, the world was not.
 */
final class DockerUnavailable extends DockerException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
