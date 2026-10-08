<?php

declare(strict_types=1);

namespace App\Exec;

use InvalidArgumentException;

/**
 * A request that cannot be satisfied as written.
 *
 * Refused rather than repaired. The design rule is that a value which escapes
 * its bounds is *rejected*, not silently normalised (DOCKER-ACCESS.md §5a) —
 * quietly turning `/etc` into `/workspace/etc` would make the boundary look
 * like it held while the caller was asking for something else entirely.
 */
final class InvalidExecRequest extends InvalidArgumentException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
