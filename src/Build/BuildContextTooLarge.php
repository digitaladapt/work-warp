<?php

declare(strict_types=1);

namespace App\Build;

use RuntimeException;

/**
 * The build context exceeded `BuildRequest::MAX_CONTEXT_BYTES`.
 *
 * Thrown by the counting stream while the context is being uploaded — a
 * refusal mid-flight, measured to reach the caller unwrapped (a throw from the
 * HTTP client's body callback propagates as itself). 413 rather than 422: the
 * request is well-formed, it is simply bigger than this broker will carry.
 */
final class BuildContextTooLarge extends RuntimeException
{
    /**
     * One message, two callers: the stream raises this as an exception when
     * the cap trips mid-flight, and the controller needs the same sentence for
     * the declared-length case, where nothing has been read yet.
     */
    public static function message(int $maxBytes): string
    {
        return \sprintf(
            'the build context exceeds the %d-byte cap; exclude .git, vendor and build output from the tar (-c is deliberate about what goes in)',
            $maxBytes,
        );
    }

    public static function because(int $maxBytes): self
    {
        return new self(self::message($maxBytes));
    }
}
