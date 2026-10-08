<?php

declare(strict_types=1);

namespace App\Tests\Unit\Exec;

use App\Exec\Slots;
use App\Exec\SlotUnavailable;
use App\Tests\Support\FakeClock;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * @internal
 */
final class SlotsTest extends TestCase
{
    /**
     * Must match the interval `Slots::reserve()` sleeps between attempts: the
     * wait's bound is expressed in intervals, not in exact seconds.
     */
    private const float SLEEP_INTERVAL_SECONDS = 0.05;

    public function test_a_pool_never_hands_out_more_slots_than_its_capacity(): void
    {
        $pool = Slots::of(2, new InMemoryStore());

        $pool->reserve();
        $pool->reserve();

        $this->expectException(SlotUnavailable::class);
        $pool->reserve();
    }

    public function test_the_refusal_says_the_broker_does_not_queue_rather_than_leaving_the_caller_guessing(): void
    {
        $pool = Slots::of(1, new InMemoryStore());
        $pool->reserve();

        try {
            $pool->reserve();
            self::fail('a second reservation must be refused');
        } catch (SlotUnavailable $e) {
            self::assertStringContainsString('does not queue', $e->getMessage());
            self::assertStringContainsString('all 1 exec slot(s)', $e->getMessage());
        }
    }

    public function test_a_released_slot_is_available_again(): void
    {
        $pool = Slots::of(1, new InMemoryStore());

        $slot = $pool->reserve();
        self::assertFalse($slot->isReleased());

        $slot->release();
        self::assertTrue($slot->isReleased());

        $again = $pool->reserve();
        self::assertFalse($again->isReleased(), 'the slot is usable again, and honestly reports itself held');
    }

    public function test_releasing_twice_is_harmless(): void
    {
        $pool = Slots::of(1, new InMemoryStore());

        $slot = $pool->reserve();
        $slot->release();
        $slot->release();

        // Exactly one slot is available: a double release must not lose the
        // hold the next reservation takes.
        $pool->reserve();

        $this->expectException(SlotUnavailable::class);
        $pool->reserve();
    }

    public function test_a_capacity_below_one_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        Slots::of(0, new InMemoryStore());
    }

    public function test_available_reports_the_pool_as_everything_unreserved(): void
    {
        $pool = Slots::of(3, new InMemoryStore());

        self::assertSame(3, $pool->available());

        $held = $pool->reserve();
        self::assertSame(2, $pool->available());

        $held->release();
        self::assertSame(3, $pool->available());
    }

    public function test_available_reports_the_truth_immediately_after_a_reservation(): void
    {
        // Regression guard for the bug this method had on its first run: a slot
        // held *by this process* must not be reported as free. `Lock::isAcquired()`
        // answers only for the Lock object you already hold, so the count has to
        // come from attempting the acquisition.
        $pool = Slots::of(2, new InMemoryStore());

        $pool->reserve();

        self::assertSame(1, $pool->available());
    }

    public function test_waiting_gives_up_at_the_stated_time_and_says_how_long_it_waited(): void
    {
        $clock = new FakeClock();
        $pool = Slots::of(1, new InMemoryStore());
        $pool->reserve();

        try {
            $pool->reserve(wait: 1.0, clock: $clock);
            self::fail('a full pool must refuse after the wait expires');
        } catch (SlotUnavailable $e) {
            self::assertStringContainsString('within 1.0s', $e->getMessage());
        }

        // It really waited, rather than refusing instantly or spinning forever.
        //
        // The bound is not exact and the assertion says so: the loop sleeps a
        // fixed interval and checks afterwards, so it can overshoot by up to one
        // interval plus scheduling. Requiring `>= 1.0` would make this test a
        // coin flip on a timer that woke a hair early; "clearly waited, and not
        // indefinitely" is the property that matters.
        self::assertGreaterThan(0.5, $clock->elapsedSeconds());
        self::assertLessThan(1.0 + 5 * self::SLEEP_INTERVAL_SECONDS, $clock->elapsedSeconds());
    }

    public function test_a_caller_that_asks_to_wait_is_admitted_when_a_slot_frees(): void
    {
        $clock = new FakeClock();
        $pool = Slots::of(1, new InMemoryStore());
        $held = $pool->reserve();

        // Free the slot as though another command had finished mid-wait. The
        // fake clock advances only on sleep, so this is deterministic rather
        // than a race.
        $pool->available();
        $held->release();

        $admitted = $pool->reserve(wait: 5.0, clock: $clock);

        self::assertFalse($admitted->isReleased());
    }

    /**
     * The claim this class exists to make, tested against a real second process.
     *
     * With the flock store (the production default) a slot is held by a file
     * description in the kernel, so another broker process must lose. With a
     * counter in a variable it would win, and the cap would be advisory.
     */
    public function test_a_second_process_cannot_hold_a_slot_this_one_holds(): void
    {
        $script = \dirname(__DIR__, 2).'/Support/reserve-slot.php';

        // A capacity dedicated to this test, so it cannot collide with a broker
        // or another test run on the same machine.
        $capacity = 1;

        $pool = Slots::of($capacity, new FlockStore());

        $slot = $pool->reserve();
        self::assertSame('REFUSED', $this->reserveInAnotherProcess($script, $capacity), 'a second process must be refused while this one holds the slot');

        $slot->release();
        self::assertSame('GOT', $this->reserveInAnotherProcess($script, $capacity), 'and must be admitted once this one lets go');
    }

    private function reserveInAnotherProcess(string $script, int $capacity): string
    {
        if (!\function_exists('shell_exec')) {
            self::markTestSkipped('shell_exec is unavailable, so a second process cannot be started');
        }

        $output = shell_exec(\sprintf(
            '%s %s %d 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg($script),
            $capacity,
        ));

        self::assertIsString($output, 'the child process produced no output at all');

        return trim($output);
    }
}
