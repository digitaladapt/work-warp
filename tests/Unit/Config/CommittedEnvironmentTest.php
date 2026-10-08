<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;

/**
 * The committed test environment must be able to boot the application alone.
 *
 * CI copies `.env.test` over `.env` and then runs `composer install`, whose
 * post-install `cache:clear` compiles the container. Any variable the
 * configuration reads without a fallback therefore has to be defined in that
 * file, or a fresh checkout cannot start. A variable that exists only in the
 * untracked local `.env` boots on exactly one machine and nowhere else —
 * `DEFAULT_URI` was missing once and the whole Tests workflow failed at
 * `Install dependencies`, which is the failure this test makes impossible to
 * repeat quietly.
 *
 * @internal
 */
final class CommittedEnvironmentTest extends TestCase
{
    public function test_the_committed_environment_defines_every_variable_the_configuration_requires_without_a_fallback(): void
    {
        $missing = array_values(array_diff(
            $this->requiredVariables(),
            $this->variablesWithDefaults(),
            $this->definedVariables(),
        ));

        self::assertSame(
            [],
            $missing,
            \sprintf(
                "config/ reads these variables with no fallback, so .env.test must define them — CI copies that file over .env before `composer install` compiles the container:\n- %s",
                implode("\n- ", $missing),
            ),
        );
    }

    /**
     * Every env var the configuration reads via `%env(...)%`, minus the ones
     * that name a fallback.
     *
     * @return list<string>
     */
    private function requiredVariables(): array
    {
        $required = [];

        foreach ($this->configFiles() as $file) {
            foreach ($this->envReferences(Yaml::parseFile($file)) as $reference) {
                if (str_contains($reference, 'default:')) {
                    continue;
                }

                // Processors compose as `int:FOO` or `require:FOO`; the
                // variable is always the final segment.
                $segments = explode(':', $reference);
                $required[] = (string) end($segments);
            }
        }

        return array_values(array_unique($required));
    }

    /**
     * Variables that have a default, via a `parameters: env(FOO): value`
     * entry — Symfony uses those when the variable is not set, so the
     * application still boots without it.
     *
     * @return list<string>
     */
    private function variablesWithDefaults(): array
    {
        $defaults = [];

        foreach ($this->configFiles() as $file) {
            $parsed = Yaml::parseFile($file);

            if (!\is_array($parsed)) {
                continue;
            }

            $parameters = $parsed['parameters'] ?? null;

            if (!\is_array($parameters)) {
                continue;
            }

            foreach (array_keys($parameters) as $name) {
                if (\is_string($name) && 1 === preg_match('/^env\((.+)\)$/', $name, $matches)) {
                    $defaults[] = $matches[1];
                }
            }
        }

        return array_values(array_unique($defaults));
    }

    /**
     * The variable names `.env.test` defines, parsed plainly: the file is
     * dotenv input, and a stricter parser would only be harder to trust than
     * the thing it guards.
     *
     * @return list<string>
     */
    private function definedVariables(): array
    {
        $lines = file($this->root().'/.env.test');

        if (false === $lines) {
            self::fail('.env.test is missing; CI copies it over .env and nothing would boot.');
        }

        $defined = [];

        foreach ($lines as $line) {
            if (1 === preg_match('/^([A-Z][A-Z0-9_]*)=/', $line, $matches)) {
                $defined[] = $matches[1];
            }
        }

        return array_values(array_unique($defined));
    }

    /**
     * @return list<string>
     */
    private function envReferences(mixed $node): array
    {
        if (\is_string($node)) {
            preg_match_all('/%env\(([^)]+)\)%/', $node, $matches);

            return array_map(strval(...), $matches[1]);
        }

        if (!\is_array($node)) {
            return [];
        }

        $references = [];

        foreach ($node as $value) {
            $references = [...$references, ...$this->envReferences($value)];
        }

        return $references;
    }

    /**
     * @return list<string>
     */
    private function configFiles(): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root().'/config', FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.yaml')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function root(): string
    {
        return \dirname(__DIR__, 3);
    }
}
