<?php

declare(strict_types=1);

namespace App\Tests\Unit\Build;

use App\Build\BuildContextTooLarge;
use App\Build\BuildOutput;
use App\Build\BuildRequest;
use App\Build\BuildRunner;
use App\Docker\DockerRefused;
use App\Docker\Limits;
use App\Exec\Slots;
use App\Session\NoSuchSession;
use App\Session\SessionName;
use App\Session\SessionNetwork;
use App\Tests\Support\ScriptedDockerApi;
use Generator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * The build lifecycle: session check, slot, network, query, stream, and the
 * one question that settles the outcome.
 *
 * Everything Docker is scripted, so what is asserted is what the broker
 * *builds* and what it *asks* — the same standard as the rest of the suite.
 * The interesting cases are the ones where a build's result is not just its
 * stream: a failure must not report a stale image, and a success that the
 * clock cut in the same instant must still report its image.
 *
 * @internal
 */
final class BuildRunnerTest extends TestCase
{
    public function test_a_build_that_produces_an_image_reports_its_reference(): void
    {
        [$docker, , $runner] = $this->mount();
        $docker->scriptNextBuild(new BuildOutput("Step 1/2\nSuccessfully built abc\n", false, false, false), tagged: true);

        $result = $runner->run(
            SessionName::from('demo'),
            BuildRequest::fromQuery(['tag' => 'intl']),
            'the tar bytes',
        );

        self::assertSame('ww-demo-intl:latest', $result->image);
        self::assertSame("Step 1/2\nSuccessfully built abc\n", $result->output);
        self::assertFalse($result->timedOut);
        self::assertFalse($result->truncated);
        self::assertSame(['volumeExists', 'buildImage', 'imageExists'], $docker->calledMethods());
    }

    public function test_a_build_the_daemon_reported_as_failed_never_reports_a_stale_image(): void
    {
        // The image exists beforehand — a rebuild replaces a previous image —
        // so a lookup would answer "yes" and attach the old artefact to a
        // failed build. The reported failure must skip the lookup entirely.
        [$docker, , $runner] = $this->mount();
        $docker->seedImage('ww-demo-intl:latest');
        $docker->scriptNextBuild(new BuildOutput("error: unshare: operation not permitted\n", false, false, failed: true), tagged: false);

        $result = $runner->run(
            SessionName::from('demo'),
            BuildRequest::fromQuery(['tag' => 'intl']),
            'the tar bytes',
        );

        self::assertNull($result->image, 'a failed build must not be attributed the previous image');
        self::assertStringContainsString('unshare', $result->output);
        self::assertSame(['volumeExists', 'buildImage'], $docker->calledMethods(), 'the lookup is skipped, not merely ignored');
    }

    /**
     * The race the image lookup exists for: the build finished and tagged the
     * image as the broker's clock cut the stream. The result carries both
     * facts, because both are true.
     */
    public function test_a_build_that_tagged_as_the_clock_cut_the_stream_is_reported_as_both(): void
    {
        [$docker, , $runner] = $this->mount();
        $docker->scriptNextBuild(new BuildOutput('partial log', false, true, false), tagged: true);

        $result = $runner->run(
            SessionName::from('demo'),
            BuildRequest::fromQuery(['tag' => 'intl', 'timeout' => 60]),
            'the tar bytes',
        );

        self::assertSame('ww-demo-intl:latest', $result->image);
        self::assertTrue($result->timedOut);
    }

    public function test_a_missing_session_is_refused_before_anything_is_asked_of_docker(): void
    {
        [$docker, , $runner] = $this->mount(createSession: false);

        try {
            $runner->run(SessionName::from('ghost'), BuildRequest::fromQuery(['tag' => 'intl']), 'tar');
            self::fail('a missing session must be refused');
        } catch (NoSuchSession $e) {
            self::assertStringContainsString('no session named "ghost"', $e->getMessage());
        }

        self::assertSame(['volumeExists'], $docker->calledMethods(), 'a mistyped name must not become a durable object, build or not');
    }

    public function test_the_session_network_is_created_only_when_the_build_asked_to_reach_it(): void
    {
        [$docker, , $runner] = $this->mount();

        $docker->scriptNextBuild(new BuildOutput('', false, false, false), tagged: true);
        $runner->run(SessionName::from('demo'), BuildRequest::fromQuery(['tag' => 'x', 'network' => 'none']), 'tar');

        self::assertNotContains('networkExists', $docker->calledMethods(), 'a hermetic build must not create a network');

        $docker->resetCalls();
        $docker->scriptNextBuild(new BuildOutput('', false, false, false), tagged: true);
        $runner->run(SessionName::from('demo'), BuildRequest::fromQuery(['tag' => 'x', 'network' => 'session']), 'tar');

        self::assertSame(['volumeExists', 'networkExists', 'createNetwork', 'buildImage', 'imageExists'], $docker->calledMethods());
    }

    public function test_the_query_is_the_one_buildspec_builds_and_the_caller_never_assembles_it(): void
    {
        [$docker, , $runner] = $this->mount();
        $docker->scriptNextBuild(new BuildOutput('', false, false, false), tagged: true);

        $runner->run(SessionName::from('demo'), BuildRequest::fromQuery(['tag' => 'intl', 'timeout' => 900]), 'tar');

        $query = $docker->calls()[1]['args']['query'];

        self::assertSame('ww-demo-intl:latest', $query['t']);
        self::assertSame('1', $query['version'], 'the classic builder, deliberately — BuildKit refuses custom networks');
        self::assertSame('1', $query['rm']);
        self::assertSame('1', $query['forcerm']);
        self::assertSame('none', $query['networkmode']);
        self::assertSame((string) (new Limits())->memoryBytes, $query['memory']);
        self::assertJsonStringEqualsJsonString(
            json_encode(['ww.created-by' => 'work-warp', 'ww.session' => 'demo', 'ww.kind' => 'build'], \JSON_THROW_ON_ERROR),
            $query['labels'],
        );
        self::assertSame(900.0, $docker->calls()[1]['args']['timeout'], 'the caller\'s budget is the connection\'s budget');
    }

    public function test_the_slot_is_released_when_the_build_fails(): void
    {
        [$docker, $slots, $runner] = $this->mount();
        $docker->failNext('buildImage', DockerRefused::because('the daemon said no'));

        try {
            $runner->run(SessionName::from('demo'), BuildRequest::fromQuery(['tag' => 'intl']), 'tar');
            self::fail('a refused build must not pass silently');
        } catch (DockerRefused) {
            // The point.
        }

        self::assertSame(2, $slots->available(), 'a failed build must not hold its slot');
    }

    public function test_the_context_cap_trips_while_streaming_and_releases_the_slot(): void
    {
        [$docker, $slots, $runner] = $this->mount();

        $oversized = (static function (): Generator {
            for ($i = 0; $i < 3; ++$i) {
                yield str_repeat('x', 64 * 1024 * 1024);
            }
        })();

        try {
            $runner->run(SessionName::from('demo'), BuildRequest::fromQuery(['tag' => 'intl']), $oversized);
            self::fail('an oversized context must be refused');
        } catch (BuildContextTooLarge $e) {
            self::assertStringContainsString('134217728-byte cap', $e->getMessage());
        }

        self::assertSame(2, $slots->available());
    }

    /**
     * One test's small world: the scripted daemon, a fresh slot pool, and the
     * runner wired the way the container wires it.
     *
     * @return array{ScriptedDockerApi, Slots, BuildRunner}
     */
    private function mount(bool $createSession = true): array
    {
        $docker = new ScriptedDockerApi();
        $slots = Slots::of(2, new InMemoryStore());
        $runner = new BuildRunner($docker, $slots, new SessionNetwork($docker));

        if ($createSession) {
            $docker->createVolume('ww-ws-demo', ['ww.session' => 'demo']);
        }

        $docker->resetCalls();

        return [$docker, $slots, $runner];
    }
}
