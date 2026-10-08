<?php

declare(strict_types=1);

namespace App\Exec;

use Symfony\Component\Lock\LockInterface;
use Throwable;

/**
 * A hold on one exec slot. Release it when the command finishes, and not
 * before — the slot *is* the budget.
 *
 * The hold is the lock: hold the `Slot` for as long as the command runs. It is
 * not an auto-releasing lock, because a slot given back by the garbage collector
 * is one released at a moment nobody chose — and here that moment would be
 * "while a command is still running". `release()` is idempotent, so an
 * over-eager caller cannot do damage either.
 */
final class Slot
{
    private bool $released = false;

    /**
     * @internal constructed by Slots::reserve()
     */
    public function __construct(private readonly LockInterface $lock)
    {
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;

        try {
            $this->lock->release();
        } catch (Throwable) {
            // A double release, or a handle that went away with a forked child.
            // Either way the slot is available, which is the only thing the
            // caller cares about.
        }
    }

    /**
     * Whether this hold has been given back. For tests and diagnostics.
     */
    public function isReleased(): bool
    {
        return $this->released;
    }
}
