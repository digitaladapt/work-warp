<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Exec\Slots;
use App\Exec\SlotUnavailable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The exec budget is a service, wired from configuration.
 *
 * Its factory and the `WW_MAX_CONCURRENCY` parameter are the only thing standing
 * between "a pool that enforces a cap" and "a pool that enforces whatever the
 * container happens to have built", so the wiring is worth a test rather than a
 * `debug:container` someone ran once.
 *
 * @internal
 */
final class SlotsWiringTest extends KernelTestCase
{
    public function test_the_budget_is_built_from_configuration_with_the_configured_capacity(): void
    {
        self::bootKernel();

        $slots = self::getContainer()->get(Slots::class);

        self::assertInstanceOf(Slots::class, $slots);
        self::assertSame(
            (int) self::getContainer()->getParameter('max_concurrency'),
            $slots->capacity(),
            'the pool must take its size from the parameter, not from a default',
        );
    }

    public function test_the_test_environment_capacity_is_the_configured_one(): void
    {
        // .env.test sets WW_MAX_CONCURRENCY=1 so that the suite never competes
        // for slots with a broker running on the same machine.
        self::bootKernel();

        self::assertSame(1, self::getContainer()->get(Slots::class)->capacity());
    }

    public function test_a_pool_taken_from_the_container_enforces_its_cap(): void
    {
        self::bootKernel();

        $slots = self::getContainer()->get(Slots::class);

        $slot = $slots->reserve();

        try {
            $this->expectException(SlotUnavailable::class);
            $slots->reserve();
        } finally {
            $slot->release();
        }
    }
}
