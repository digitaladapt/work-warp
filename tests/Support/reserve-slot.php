<?php

declare(strict_types=1);

/**
 * Reserves one exec slot in a separate process and reports the outcome.
 *
 * A fixture, not a test: SlotsTest runs this to prove that slot enforcement is
 * kernel state rather than a variable in one process's memory. If two broker
 * processes could each hold "the" slot, the cap would be advisory, and the
 * honest way to show they cannot is to actually start a second process.
 *
 * Prints GOT or REFUSED and always exits 0, so the parent asserts on the
 * message rather than on an exit code it could get from a crash.
 *
 * The capacity is taken as an argument and defaults to `SlotsTest`'s dedicated
 * value, so this fixture cannot collide with a real broker running on the same
 * machine with the production capacity.
 *
 * Usage: php tests/Support/reserve-slot.php <capacity>
 */

use App\Exec\Slots;
use App\Exec\SlotUnavailable;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    Slots::of((int) ($argv[1] ?? 1))->reserve();
    echo "GOT\n";
} catch (SlotUnavailable) {
    echo "REFUSED\n";
}
