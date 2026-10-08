<?php

declare(strict_types=1);

namespace App\Exec;

use RuntimeException;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\FlockStore;
use Throwable;

/**
 * How many commands may run at once — enforced with the filesystem, not with a
 * variable.
 *
 * A process that counts its own children cannot be honest about a crash: the
 * count survives in the code and not in reality, and after a restart nobody can
 * say whether the four "running" commands are running. A lock lives in the
 * kernel, so when the thing holding it dies the slot is free again — no
 * reconciliation step, and nothing left to reconcile.
 *
 * **The shape of the mechanism, which is not obvious.** `FlockStore` has no
 * capacity: it is `flock()` on a hashed filename, one lock per key. It is a
 * mutex, not a semaphore. Capacity is therefore a *pool of N distinct slots*,
 * each its own lock, and "how many are running" is "how many slots are taken".
 * Holding slots is holding the budget; there is no counter to drift.
 *
 * **Every reservation is a fresh Lock object, deliberately.** A `Lock` is
 * re-entrant with respect to itself — a second `acquire()` on the same object
 * from the same process succeeds, because the store reports it as already held
 * by this token. Handing out one cached `Lock` per slot therefore lets the pool
 * exceed its own capacity, which is the bug this class had the first time it
 * ran: it happily issued three reservations from a pool of two while the
 * cross-process behaviour was correct all along. A fresh object per attempt
 * makes the same-process case go through exactly the same conflict path as a
 * foreign process, so there is one mechanism rather than two that must agree.
 *
 * **Scope is per workspace, and today that is the same thing as per session.**
 * `POST /v1/sessions` creates a session and its workspace together, so a global
 * cap and a per-session cap would be indistinguishable until sessions can share
 * a workspace. The rule that stays meaningful after that change is the one
 * written down here: *a workspace is the unit that has a budget, and its size is
 * a property of the workspace.* When a session may name an existing workspace,
 * capacity comes from that workspace rather than from configuration.
 *
 * **What the hold actually is.** `reserve()` returns a `Slot`, and the `Slot`
 * owns the lock. Hold it for as long as the command runs; release it when the
 * command finishes. If the `Slot` goes out of scope the file descriptor closes
 * and the slot frees — which is the right failure direction (a caller that
 * stopped caring should not wedge the budget) but is not a licence to let a
 * running command's `Slot` be garbage: keep it in scope until the command is
 * reaped.
 */
final class Slots
{
    /**
     * One pool per capacity, so two pools can never collide on slot names.
     * Process-wide on purpose: creating a pool per request would free a slot
     * when the request ended, which is the opposite of the intent.
     *
     * @var array<int, self>
     */
    private static array $pools = [];

    private const string SLOT_PREFIX = 'ww.exec.slot';

    private function __construct(
        private readonly int $capacity,
        private readonly LockFactory $locks,
    ) {
    }

    /**
     * @param PersistingStoreInterface|null $store an `InMemoryStore` for a fresh,
     *                                             process-local pool (tests); production uses flock
     */
    public static function of(int $capacity, ?PersistingStoreInterface $store = null): self
    {
        if ($capacity < 1) {
            throw new RuntimeException(\sprintf('capacity must be at least 1, got %d', $capacity));
        }

        // A caller-supplied store gets a fresh pool each time, deliberately:
        // tests must not inherit one another's slots through the cache below.
        if (null !== $store) {
            return new self($capacity, new LockFactory($store));
        }

        // FlockStore needs a directory, not a file; the system temp directory is
        // the right home for state whose whole value is being kernel state.
        return self::$pools[$capacity] ??= new self($capacity, new LockFactory(new FlockStore()));
    }

    /**
     * Take a slot, or refuse.
     *
     * @param float $wait seconds to keep trying; 0.0 means "do not queue"
     *
     * @throws SlotUnavailable when nothing frees up
     */
    public function reserve(float $wait = 0.0, ?ClockInterface $clock = null): Slot
    {
        $clock ??= new Clock();

        $slot = $this->tryAny();

        if (null !== $slot) {
            return $slot;
        }

        if ($wait <= 0.0) {
            throw SlotUnavailable::immediately($this->capacity);
        }

        // A float deadline, not a DateTimeImmutable one. Measured: PHP's
        // relative format silently ignores fractional units —
        // `modify('+1.000 seconds')` returns the *original* time, so a deadline
        // built that way never arrives and a caller asking to wait gets an
        // immediate refusal instead. Seconds as a number have no such trap.
        $giveUpAt = $this->secondsNow($clock) + $wait;

        while ($this->secondsNow($clock) < $giveUpAt) {
            $clock->sleep(0.05);
            $slot = $this->tryAny();

            if (null !== $slot) {
                return $slot;
            }
        }

        throw SlotUnavailable::afterWaiting($wait, $this->capacity);
    }

    /**
     * How many slots are free right now. For reporting only — anything that
     * decided *whether to run* from this would be wrong, because the answer can
     * change before it acts. `reserve()` is the only authority.
     */
    public function available(): int
    {
        $free = 0;

        for ($slot = 0; $slot < $this->capacity; ++$slot) {
            if ($this->isFree($slot)) {
                ++$free;
            }
        }

        return $free;
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    /**
     * @internal constructed by Slots::reserve()
     */
    public function lockFor(int $slot): LockInterface
    {
        return $this->locks->createLock(
            $this->slotName($slot),
            // ttl null: a slot must not expire while the command it guards is
            // still running. autoRelease false: only an explicit release, or the
            // process ending, may give a slot back.
            ttl: null,
            autoRelease: false,
        );
    }

    /**
     * Whether a slot is free, by taking it and letting go.
     *
     * `Lock::isAcquired()` cannot answer this: it reports only on the Lock
     * object you already hold, so a slot held by another process — or by another
     * `Slot` in this one — would read as free. Acquisition is the only question
     * with an answer, which is also why this must never be used to decide
     * anything.
     */
    private function isFree(int $slot): bool
    {
        $probe = $this->lockFor($slot);

        if (!$probe->acquire()) {
            return false;
        }

        $probe->release();

        return true;
    }

    private function tryAny(): ?Slot
    {
        for ($slot = 0; $slot < $this->capacity; ++$slot) {
            $lock = $this->lockFor($slot);

            try {
                if ($lock->acquire()) {
                    return new Slot($lock);
                }
            } catch (Throwable $e) {
                // A store that cannot be read is not a store that said "free".
                // Refusing is the safe direction: the alternative is unbounded
                // concurrency because the lock directory went missing.
                throw SlotUnavailable::dueTo($e);
            }
        }

        return null;
    }

    private function slotName(int $slot): string
    {
        // The capacity is part of the name so that pools of different sizes can
        // never share a slot by accident.
        return \sprintf('%s.%d.%d', self::SLOT_PREFIX, $this->capacity, $slot);
    }

    /**
     * The clock as seconds since the epoch, fraction included.
     *
     * `U.u` is exact and independent of the clock's timezone; a `DateTimeImmutable`
     * comparison would work too, but the arithmetic that produced the deadline has
     * to be exact for the comparison to mean anything.
     */
    private function secondsNow(ClockInterface $clock): float
    {
        return (float) $clock->now()->format('U.u');
    }
}
