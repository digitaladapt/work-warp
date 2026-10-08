<?php

declare(strict_types=1);

namespace App\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Override;
use RuntimeException;
use Symfony\Component\Clock\ClockInterface;

/**
 * A clock that moves only when something asks it to sleep.
 *
 * The exec-budget wait is expressed in sleep intervals, so a real clock would
 * make the test either slow (wait for real seconds) or flaky (assert on wall
 * time). This one advances exactly as instructed and reports what it was asked
 * for, which is what makes "did it wait?" answerable without waiting.
 *
 * A named class rather than an anonymous one for a mundane reason: PHPStan
 * cannot call a method on an anonymous class returned as an interface, and the
 * code formatter reflows anonymous classes inside test bodies into something
 * that is hard to edit without breaking.
 */
final class FakeClock implements ClockInterface
{
    private DateTimeImmutable $now;

    /** @var list<float> */
    private array $slept = [];

    public function __construct(string $start = '2026-01-01 00:00:00')
    {
        $this->now = new DateTimeImmutable($start, new DateTimeZone('UTC'));
    }

    #[Override]
    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    #[Override]
    public function sleep(float|int $seconds): void
    {
        $this->slept[] = (float) $seconds;

        // Rebuilt from the epoch rather than advanced with modify(): PHP's
        // relative date format silently ignores fractional units, so
        // modify('+0.05 seconds') would not move this clock at all — the same
        // trap that put a never-arriving deadline in Slots.
        $this->now = new DateTimeImmutable(
            \sprintf('@%.6F', (float) $this->now->format('U.u') + (float) $seconds),
        );
    }

    #[Override]
    public function withTimeZone(DateTimeZone|string $timezone): static
    {
        throw new RuntimeException('not used in these tests');
    }

    /**
     * Total seconds this clock was asked to sleep: "how long did the caller wait".
     */
    public function elapsedSeconds(): float
    {
        return array_sum($this->slept);
    }
}
