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

    /**
     * The runtime stage must install the extensions the broker depends on at
     * run time but does not declare in composer.json.
     *
     * `ww:janitor` implements `SignalableCommandInterface`, so the sweep loop
     * cannot even start without pcntl: `Application::getSignalRegistry()`
     * throws, and the entrypoint's `while true` + `sleep 5` subshell turns that
     * one failure into an endless retry that nobody reads. The broker answers
     * requests throughout, which is what makes it worth a test: the symptom is
     * host volumes quietly never being reaped, not a container that is down.
     *
     * Re-verified 2026-10-14 by running `ww:janitor --every 5` with the pcntl_*
     * functions disabled: it dies at start with the message below, and the same
     * command runs with the extension present. `runtime` is the only stage that
     * gets them; the deps/build stages run composer — and the build-stage boot
     * smoke — without pcntl, which is why these are not in composer.json (a
     * platform requirement would fail `composer install` in a stage that
     * deliberately lacks the extension).
     */
    public function test_the_runtime_stage_installs_the_extensions_the_broker_needs_at_run_time(): void
    {
        $runtime = $this->runtimeStage();

        foreach (['pcntl', 'intl'] as $extension) {
            self::assertMatchesRegularExpression(
                '/^\s*&&\s+install-php-extensions\b[^\n]*\b'.preg_quote($extension, '/').'\b/m',
                $runtime,
                \sprintf(
                    'The runtime stage must install `%s`. It is not in composer.json (the deps/build stages run composer without it), so nothing else fails when it goes missing: %s',
                    $extension,
                    'pcntl' === $extension
                        ? 'without pcntl the entrypoint\'s janitor loop dies at start with "Signals are not supported" and retries every five seconds forever, so the host is never swept and nothing reports it.'
                        : 'without intl the failure would surface as a missing-class or missing-function error at run time, in whichever request or sweep first needed it.',
                ),
            );
        }

        // The premise of the check above: these are run-time-only, so the stage
        // that runs composer must not be the stage that installs them.
        self::assertStringNotContainsString(
            'install-php-extensions',
            $this->stage('deps'),
            'The deps stage runs `composer install`; if it also installed these, they could have been declared as platform requirements instead and this test would be guarding the wrong thing.',
        );
    }

    public function test_the_janitor_loop_needs_the_extension_this_image_installs(): void
    {
        // Tie the extension to the feature that needs it, so a future janitor
        // that no longer subscribes to signals does not keep the extension by
        // habit, and one that does cannot ship without it.
        $command = $this->read('src/Janitor/JanitorCommand.php');

        self::assertStringContainsString(
            'SignalableCommandInterface',
            $command,
            'The janitor subscribes to SIGTERM/SIGINT through Symfony Console, which requires pcntl at run time. If that changed, the pcntl install in the runtime stage should be revisited.',
        );

        $entrypoint = $this->read('docker/entrypoint.sh');

        self::assertStringContainsString(
            'while true',
            $entrypoint,
            'The entrypoint restarts the janitor in a loop, which is what turns a missing pcntl into a silent, permanent failure rather than a visible crash — the reason the extension is asserted here.',
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
     * The body of one `FROM ... AS <name>` stage, up to the next stage.
     */
    private function stage(string $name): string
    {
        if (1 !== preg_match('/^FROM\s+\S+\s+AS\s+'.preg_quote($name, '/').'\s*$(.*?)(?=^FROM\s|\z)/ms', $this->read('Dockerfile'), $matches)) {
            throw new RuntimeException(\sprintf('the Dockerfile has no stage named "%s"; the boot contract is asserted against it by name.', $name));
        }

        return $matches[1];
    }

    private function runtimeStage(): string
    {
        return $this->stage('app');
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
