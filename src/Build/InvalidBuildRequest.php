<?php

declare(strict_types=1);

namespace App\Build;

use InvalidArgumentException;

/**
 * A build request that cannot be satisfied as written.
 *
 * Refused rather than repaired, for the same reason `InvalidExecRequest` is:
 * quietly normalising something that escapes its bounds makes the boundary look
 * like it held while the caller was asking for something else entirely
 * (DOCKER-ACCESS.md §5a).
 */
final class InvalidBuildRequest extends InvalidArgumentException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
