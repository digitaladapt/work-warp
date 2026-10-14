<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Docker\FileSize;
use App\Exec\ExecRunner;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The workspace write cap is a service, wired from configuration.
 *
 * The parse itself is tested in `FileSizeTest`, and what the container is sent
 * is tested in `ContainerSpecTest`. What is left is the middle: that
 * `WW_MAX_FILE_SIZE` actually reaches the command runner, and that its absence
 * or its corruption does not become a silently unbounded workspace. A `mixed`
 * segment is where a limit most easily stops existing, so it is asserted
 * rather than assumed from the fact that the config file mentions it.
 *
 * @internal
 */
final class FileSizeWiringTest extends KernelTestCase
{
    public function test_the_cap_is_built_from_configuration(): void
    {
        self::bootKernel();

        $cap = self::getContainer()->get(FileSize::class);

        self::assertInstanceOf(FileSize::class, $cap);
        self::assertSame(
            FileSize::parse((string) self::getContainer()->getParameter('max_file_size'))->bytes,
            $cap->bytes,
            'the cap must take its size from the parameter, not from a default',
        );
    }

    public function test_the_test_environment_cap_is_the_configured_one(): void
    {
        // .env.test sets WW_MAX_FILE_SIZE=7M, a value no default would produce,
        // so this fails if the parameter stops being threaded through.
        self::bootKernel();

        self::assertSame(7 * 1024 ** 2, self::getContainer()->get(FileSize::class)->bytes);
    }

    /**
     * The runner is the thing that actually builds a container, so the cap has
     * to arrive there and not merely exist in the container.
     */
    public function test_the_exec_runner_carries_the_configured_cap(): void
    {
        self::bootKernel();

        self::assertSame(7 * 1024 ** 2, self::getContainer()->get(ExecRunner::class)->maxFileSize->bytes);
    }
}
