<?php

declare(strict_types=1);

namespace App\Janitor;

/**
 * A container the sweep decided to act on and could not.
 *
 * Kept beside the successes rather than thrown, because a sweep is a batch
 * operation: one container whose stop the daemon refuses must not stop the
 * rest of the sweep, and the report is the place the failure is visible. The
 * next sweep will try again — the container is still past its TTL and still
 * the broker's.
 */
final class ReapFailure
{
    public function __construct(
        public readonly string $container,
        public readonly string $reason,
    ) {
    }

    /**
     * @return array{container: string, reason: string}
     */
    public function toArray(): array
    {
        return [
            'container' => $this->container,
            'reason' => $this->reason,
        ];
    }
}
