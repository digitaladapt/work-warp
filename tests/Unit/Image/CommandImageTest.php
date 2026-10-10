<?php

declare(strict_types=1);

namespace App\Tests\Unit\Image;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The command image's contract, held as far as a test without a daemon can hold
 * it.
 *
 * ww-base's load-bearing properties — the uid commands run as, and a fresh
 * volume mounted at /workspace being writable by that uid — are only provable
 * against a daemon, and this suite deliberately has none (CONTRIBUTING.md).
 * The daemon half lives in `ww-base/smoke.sh`, run on a machine that has one.
 *
 * What this test can do is keep the files those checks depend on from quietly
 * losing what they depend on: the ownership the image must ship, the absence of
 * a default command, the publication naming, and the committed default that
 * points at it.
 *
 * @internal
 */
final class CommandImageTest extends TestCase
{
    public function test_the_image_ships_workspace_owned_by_the_uid_commands_run_as(): void
    {
        $dockerfile = $this->read('ww-base/Dockerfile');

        self::assertMatchesRegularExpression(
            '/^USER 1000:1000$/m',
            $dockerfile,
            'Commands run as 1000:1000 (App\Docker\Limits) and nothing here may run as root; the image must default to the same uid.',
        );

        self::assertMatchesRegularExpression(
            '~install -d -o 1000 -g 1000 -m 0755 /workspace~',
            $dockerfile,
            '/workspace must be created already owned by 1000:1000. A fresh volume inherits this ownership from the image — the daemon copies the image directory into the empty volume and chowns it — and nothing at runtime can replace it.',
        );
    }

    public function test_the_last_user_instruction_is_not_root(): void
    {
        /** @var list<string> $users */
        $users = [];

        foreach ($this->lines($this->read('ww-base/Dockerfile')) as $line) {
            if (str_starts_with($line, 'USER ')) {
                $users[] = substr($line, 5);
            }
        }

        self::assertNotSame([], $users, 'The image must declare a USER; running as root by default is how a mistake here gets expensive.');
        self::assertSame('1000:1000', end($users), 'The last USER wins, so the last one must be the non-root one.');
    }

    public function test_the_image_carries_no_default_command(): void
    {
        /** @var array<string, list<string>> $found */
        $found = [];

        foreach ($this->lines($this->read('ww-base/Dockerfile')) as $line) {
            foreach (['CMD', 'ENTRYPOINT'] as $keyword) {
                if (str_starts_with($line, $keyword.' ')) {
                    $found[$keyword][] = substr($line, \strlen($keyword) + 1);
                }
            }
        }

        // Both must appear, and both must be empty: debian:trixie-slim ships
        // Cmd: ["bash"], so clearing the default is an action, not an
        // omission. Removing either line silently reintroduces it.
        foreach (['CMD', 'ENTRYPOINT'] as $keyword) {
            self::assertArrayHasKey(
                $keyword,
                $found,
                $keyword.' must appear, in the empty-array form: it exists to clear what debian:trixie-slim ships, not to add anything.',
            );

            foreach ($found[$keyword] as $arguments) {
                self::assertSame(
                    '[]',
                    $arguments,
                    $keyword.' may only ever be "[]". The command is the broker\'s, passed per create; a default here is a command nobody asked for.',
                );
            }
        }
    }

    public function test_the_image_is_published_as_a_suffixed_tag_of_the_same_repository(): void
    {
        $bake = $this->withoutComments($this->read('docker-bake.hcl'));

        self::assertMatchesRegularExpression(
            '/group "default" \{\s*targets\s*=\s*\["app", "base"\]/',
            $bake,
            'The default group must carry both images, or a release publishes only half of them.',
        );

        $block = $this->targetBlock($bake, 'base');

        self::assertMatchesRegularExpression('~context\s*=\s*"ww-base"~', $block, 'The command image builds from ww-base/ alone.');
        self::assertMatchesRegularExpression('~dockerfile\s*=\s*"Dockerfile"~', $block, 'Spelled relative to its context — see the note in docker-bake.hcl for why it is not "ww-base/Dockerfile".');
        self::assertStringContainsString('${TAG}-base', $block, 'The command image is a suffixed tag of the broker\'s repository (:develop-base, :latest-base).');
        self::assertStringContainsString('${VERSION}-base', $block, 'A release must also tag the command image with its version.');

        $dockerfiles = $this->dockerfileValues($bake);

        self::assertNotEmpty($dockerfiles, 'No `dockerfile = "..."` was found in docker-bake.hcl; has the file changed shape?');

        foreach ($dockerfiles as $value) {
            self::assertDoesNotMatchRegularExpression(
                '~^ww-base/~',
                $value,
                'dockerfile paths resolve relative to context (buildx >= 0.12): "ww-base/Dockerfile" with context "ww-base" resolves to ww-base/ww-base/Dockerfile and fails with an lstat that reads nothing like a bake-file mistake.',
            );
        }
    }

    public function test_the_committed_defaults_point_at_the_published_name(): void
    {
        // The one setting that decides what commands run inside of. A default
        // that names a tag no registry serves is a default that cannot pull.
        $shape = '~^[a-z0-9][a-z0-9-]*/work-warp:[A-Za-z0-9._-]+-base$~';

        $example = $this->read('.env.example');

        self::assertSame(1, preg_match('~^WW_IMAGE=(.+)$~m', $example, $matches), '.env.example must document WW_IMAGE.');
        self::assertMatchesRegularExpression($shape, trim($matches[1]), 'WW_IMAGE in .env.example must name the command image as published: a suffixed tag (:develop-base, :latest-base, :<version>-base) of the broker\'s repository.');

        $services = $this->read('config/services.yaml');

        self::assertSame(1, preg_match("~env\\(WW_IMAGE\\): '([^']+)'~", $services, $matches), 'config/services.yaml must carry the WW_IMAGE fallback.');
        self::assertMatchesRegularExpression($shape, $matches[1], 'The WW_IMAGE fallback in config/services.yaml must name the command image as published.');
    }

    public function test_the_smoke_script_still_matches_the_brokers_container_shape(): void
    {
        $smoke = $this->read('ww-base/smoke.sh');

        foreach (['--read-only', '--cap-drop=ALL', '--no-new-privileges', '--network none', '--user 1000:1000'] as $flag) {
            self::assertStringContainsString($flag, $smoke, 'smoke.sh emulates the broker\'s container shape; when the shape changes, this file has to change with it.');
        }

        self::assertStringContainsString('docker volume create', $smoke, 'The writability check must use a fresh volume — an existing one proves nothing about volume population.');
    }

    /**
     * @return list<string>
     */
    private function lines(string $contents): array
    {
        $lines = [];

        foreach (explode("\n", $contents) as $line) {
            $lines[] = trim($line);
        }

        return $lines;
    }

    private function withoutComments(string $contents): string
    {
        $kept = [];

        foreach (explode("\n", $contents) as $line) {
            if (!str_starts_with(ltrim($line), '#')) {
                $kept[] = $line;
            }
        }

        return implode("\n", $kept);
    }

    private function targetBlock(string $bake, string $name): string
    {
        if (1 !== preg_match('~target "'.$name.'" \{(.*?)\n\}~s', $bake, $matches)) {
            throw new RuntimeException(\sprintf('docker-bake.hcl no longer contains a "%s" target; the command image would not be published.', $name));
        }

        return $matches[1];
    }

    /**
     * @return list<string>
     */
    private function dockerfileValues(string $bake): array
    {
        preg_match_all('~dockerfile\s*=\s*"([^"]*)"~', $bake, $matches);

        return $matches[1];
    }

    private function read(string $relativePath): string
    {
        $path = \dirname(__DIR__, 3).'/'.$relativePath;
        $contents = file_get_contents($path);

        if (!\is_string($contents)) {
            throw new RuntimeException($path.' must exist — it is where the command image\'s contract lives.');
        }

        return $contents;
    }
}
