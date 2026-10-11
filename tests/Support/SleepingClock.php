<?php

declare(strict_types=1);

namespace App\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Override;
use RuntimeException;
use Symfony\Component\Clock\ClockInterface;

/**
 * A clock that gives the sleeping itself to a caller-supplied hook.
 *
 * `FakeClock` answers "did the caller wait?"; this answers "what happens when
 * the wait ends?" — which is the only way a unit test can exercise a loop
 * whose exit is a signal without actually signalling a process. The hook runs
 * after the sleep, exactly where a real signal would land.
 *
 * A named class rather than an anonymous one for the same reason FakeClock is
 * one: PHPStan cannot call a method on an anonymous class returned as an
 * interface.
 */
final class SleepingClock implements ClockInterface
{
    /**
     * Called after every sleep, with the seconds that were slept. A property
     * rather than a constructor argument because the interesting callbacks
     * need the object they are wired into, which does not exist yet when the
     * clock is constructed.
     *
     * @var (callable(float): void)|null
     */
    public $onSleep;

    private readonly FakeClock $inner;

    public function __construct(?FakeClock $inner = null)
    {
        $this->inner = $inner ?? new FakeClock();
    }

    #[Override]
    public function now(): DateTimeImmutable
    {
        return $this->inner->now();
    }

    #[Override]
    public function sleep(float|int $seconds): void
    {
        $this->inner->sleep($seconds);

        if (null !== $this->onSleep) {
            ($this->onSleep)((float) $seconds);
        }
    }

    #[Override]
    public function withTimeZone(DateTimeZone|string $timezone): static
    {
        throw new RuntimeException('not used in these tests');
    }

    /**
     * Total seconds this clock was asked to sleep, delegated to the inner fake.
     */
    public function elapsedSeconds(): float
    {
        return $this->inner->elapsedSeconds();
    }
}
