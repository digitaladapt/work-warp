<?php

declare(strict_types=1);

namespace App\Stack;

use InvalidArgumentException;

/**
 * A stack request that cannot be satisfied as written.
 *
 * Refused rather than repaired, like every other request in this codebase: a
 * value that escapes its bounds is rejected, never normalised, so the caller
 * finds out at the edge rather than through a stack that quietly runs
 * something they did not ask for.
 */
final class InvalidStackRequest extends InvalidArgumentException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
