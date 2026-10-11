<?php

declare(strict_types=1);

namespace App\Janitor;

/**
 * The verdict of one sweep: what was looked at, what was done, what refused.
 *
 * `inspected` counts every broker-labelled container the daemon listed —
 * including the ones under their TTL that the sweep left alone — so
 * "inspected 9, reaped 0" is a meaningful answer and not indistinguishable
 * from "the daemon listed nothing". `reaped` and `failed` are ordered by the
 * sweep's own pass, which makes the report a faithful log of what happened in
 * what order.
 */
final class ReapReport
{
    /**
     * @param list<ReapedContainer> $reaped
     * @param list<ReapFailure>     $failed
     */
    public function __construct(
        public readonly int $inspected,
        public readonly array $reaped,
        public readonly array $failed,
    ) {
    }

    /**
     * @return array{inspected: int, reaped: list<array{container: string, session: string|null, action: string, age_seconds: int, ttl_seconds: int}>, failed: list<array{container: string, reason: string}>}
     */
    public function toArray(): array
    {
        return [
            'inspected' => $this->inspected,
            'reaped' => array_map(
                static fn (ReapedContainer $reaped): array => $reaped->toArray(),
                $this->reaped,
            ),
            'failed' => array_map(
                static fn (ReapFailure $failure): array => $failure->toArray(),
                $this->failed,
            ),
        ];
    }
}
