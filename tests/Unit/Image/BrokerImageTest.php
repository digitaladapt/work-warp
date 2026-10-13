<?php

declare(strict_types=1);

namespace App\Tests\Unit\Image;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The broker image's boot contract: it must start with no dotenv file.
 *
 * `.dockerignore` excludes every dotenv file — configuration comes from the
 * environment, never the image — but the Symfony runtime reads `/app/.env`
 * unconditionally unless told not to. Left unset, every boot in this image
 * died with `PathException: Unable to read "/app/.env"`: the entrypoint's
 * warmup first, and `set -e` made that fatal about a second after container
 * start. The build-stage smoke that should have caught it carried `|| true`,
 * so the same failure slipped through the build with one log line and the
 * image published anyway (found in the 2026-10-12 develop build; fixed
 * 2026-10-13).
 *
 * Without a daemon this test can hold the pieces that made that possible: the
 * switch is set, the smoke can fail, and the boot itself succeeds in a
 * subprocess with a dotenv-disabled environment, like a fresh container's.
 *
 * @internal
 */
final class BrokerImageTest extends TestCase
{
    public function test_the_runtime_stage_disables_dotenv_because_the_image_carries_no_dotenv_file(): void
    {
        self::assertMatchesRegularExpression(
            '~^ENV APP_RUNTIME_OPTIONS=\'\{"disable_dotenv":true\}\'\s*$~m',
            $this->read('Dockerfile'),
            'The image ships no .env and the runtime reads /app/.env unless told not to, so this exact value must be set (task-loom\'s images carry the same line) — without it every boot dies with PathException.',
        );

        self::assertMatchesRegularExpression(
            '/^\.env$/m',
            $this->read('.dockerignore'),
            'The premise of the line above: no dotenv file may ship in the image. If it ever does, one of these two is lying about the boot contract.',
        );
    }

    public function test_the_build_stage_boot_smoke_can_fail(): void
    {
        $smokeLines = array_values(array_filter(
            $this->lines($this->read('Dockerfile')),
            static fn (string $line): bool => str_starts_with($line, 'RUN ') && str_contains($line, 'cache:warmup'),
        ));

        self::assertNotSame([], $smokeLines, 'The build-stage boot smoke (bin/console cache:warmup) must exist — it is the check that catches a non-booting image at build time.');

        foreach ($smokeLines as $line) {
            self::assertStringContainsString(
                'APP_RUNTIME_OPTIONS=',
                $line,
                'The smoke compiles the very image that ships no .env, so it must run with the same dotenv-disabling environment as the runtime stage.',
            );

            self::assertStringNotContainsString(
                '|| true',
                $line,
                'A `|| true` on the smoke makes it unable to fail — it is exactly how the PathException that meant this image could not boot slipped through. If the smoke is not wanted, delete it; do not keep a check that cannot fail.',
            );
        }
    }

    public function test_the_boot_warmup_succeeds_without_a_dotenv_file(): void
    {
        if (!\function_exists('shell_exec')) {
            self::markTestSkipped('shell_exec is unavailable, so the boot cannot be run in a subprocess');
        }

        $command = \sprintf(
            'cd %s && env -i PATH=/usr/bin:/bin APP_ENV=prod APP_SECRET=boot-check APP_RUNTIME_OPTIONS=%s %s %s cache:warmup --env=prod --no-ansi 2>&1',
            escapeshellarg(\dirname(__DIR__, 3)),
            escapeshellarg('{"disable_dotenv":true}'),
            escapeshellarg(\PHP_BINARY),
            escapeshellarg('bin/console'),
        );

        $output = shell_exec($command);

        self::assertIsString($output, 'the boot produced no output at all');
        self::assertStringContainsString(
            'successfully warmed',
            $output,
            'The entrypoint\'s exact warmup must succeed with dotenv disabled and no .env reachable — before the fix this died with PathException, and without the DEFAULT_URI parameter default it dies on routing.yaml. Output:'.\PHP_EOL.$output,
        );
    }

    /**
     * @return list<string>
     */
    private function lines(string $contents): array
    {
        $lines = preg_split('/\R/', $contents);

        return false === $lines ? [] : $lines;
    }

    private function read(string $relativePath): string
    {
        $path = \dirname(__DIR__, 3).'/'.$relativePath;
        $contents = file_get_contents($path);

        if (!\is_string($contents)) {
            throw new RuntimeException($path.' must exist — the broker image\'s boot contract lives in it.');
        }

        return $contents;
    }
}
