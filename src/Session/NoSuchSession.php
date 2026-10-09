<?php

declare(strict_types=1);

namespace App\Session;

use RuntimeException;

/**
 * A session that does not exist.
 *
 * Refused explicitly rather than created on the way: a command aimed at a
 * mistyped session name must not silently bring a new workspace into being —
 * that would turn a typo into a durable object. A session is created by
 * `POST /v1/sessions`, and only there.
 */
final class NoSuchSession extends RuntimeException
{
    public static function named(string $name): self
    {
        return new self(\sprintf(
            'there is no session named "%s"; create it with POST /v1/sessions first',
            $name,
        ));
    }
}
