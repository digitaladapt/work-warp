<?php

declare(strict_types=1);

namespace App\Exec;

use RuntimeException;
use Throwable;

/**
 * All available exec slots are in use.
 *
 * A refusal, deliberately. The caller is told to come back rather than queued
 * indefinitely, because a broker that silently queues has made the queue the
 * caller's problem without telling them — and the caller cannot then tell
 * "waiting" from "hung", which is the exact confusion this design exists to
 * remove.
 */
final class SlotUnavailable extends RuntimeException
{
    public static function immediately(int $capacity): self
    {
        return new self(\sprintf(
            'all %d exec slot(s) are in use, and this broker does not queue. Try again when a command finishes.',
            $capacity,
        ));
    }

    public static function afterWaiting(float $seconds, int $capacity): self
    {
        return new self(\sprintf(
            'no exec slot became free within %.1fs (capacity %d). Something is holding one — check for a long-running command before retrying.',
            $seconds,
            $capacity,
        ));
    }

    public static function dueTo(Throwable $cause): self
    {
        return new self('could not determine whether an exec slot was free: '.$cause->getMessage(), 0, $cause);
    }
}
