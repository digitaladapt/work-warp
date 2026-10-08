<?php

declare(strict_types=1);

namespace App\Session;

use InvalidArgumentException;

/**
 * A session that cannot be used as a Docker namespace.
 */
final class InvalidSessionName extends InvalidArgumentException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
