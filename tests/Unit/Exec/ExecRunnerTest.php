<?php

declare(strict_types=1);

namespace App\Tests\Unit\Exec;

use App\Docker\DockerRefused;
use App\Docker\DockerUnavailable;
use App\Docker\LogOutput;
use App\Exec\ExecRequest;
use App\Exec\ExecRunner;
use App\Exec\Slots;
use App\Exec\StdinUnsupported;
use App\Session\NoSuchSession;
use App\Session\SessionName;
use App\Tests\Support\FakeClock;
use App\Tests\Support\ScriptedDockerApi;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * The command lifecycle: slot, session check, network, create, start, wait,
 * kill, logs, remove.
 *
 * Everything Docker is scripted (`ScriptedDockerApi`) and time is the fake
 * clock, so the loop that would take an hour against a real daemon runs in
 * microseconds and without one. What is asserted is the order of the calls
 * the broker makes and the result it composes — the same standard the rest of
 * the suite holds: assert what the broker *builds*, never what it reaches.
 *
 * No `setUp()` properties, deliberately: each test mounts its own small world
 * (see `mount()`), so nothing is shared between tests that should not be.
 *
 * @internal
 */
final class ExecRunnerTest extends TestCase
{
    public function test_a_command_that_ends_by_itself_reports_its_exit_code_and_streams(): void
    {
        [$docker, , , $runner] = $this->mount();
        $docker->runToCompletion(7);
        $docker->scriptNextLogs(new LogOutput("hello\n", "a warning\n"));

        $result = $runner->run(
            SessionName::from('demo'),
            ExecRequest::fromArray(['cmd' => ['true']]),
        );

        self::assertSame(7, $result->exitCode);
        self::assertSame("hello\n", $result->stdout);
        self::assertSame("a warning\n", $result->stderr);
        self::assertFalse($result->truncated);
        self::assertFalse($result->timedOut);
        self::assertSame(
            ['volumeExists', 'createContainer', 'startContainer', 'inspectContainer', 'containerLogs', 'removeContainer'],
            $docker->calledMethods(),
            'the lifecycle, in the one order the design fixes',
        );
    }

    public function test_a_command_that_overruns_its_deadline_is_killed_once_and_says_so(): void
    {
        [$docker, , $clock, $runner] = $this->mount();
        $docker->linger();
        $docker->scriptNextLogs(new LogOutput('', "Took too long\n"));

        $result = $runner->run(
            SessionName::from('demo'),
            ExecRequest::fromArray(['cmd' => ['sleep', 'forever'], 'timeout' => 1]),
        );

        self::assertTrue($result->timedOut);
        self::assertSame(137, $result->exitCode, 'the truth of what happened, not a rewritten zero');
        self::assertSame(1, \count(array_filter(
            $docker->calledMethods(),
            static fn (string $method): bool => 'killContainer' === $method,
        )), 'killed exactly once');

        // It really waited out the deadline before killing, rather than
        // killing instantly or never killing at all. The bound is deliberately
        // loose: the fake clock rebuilds its time from a microsecond-formatted
        // epoch on every sleep, so ten 0.1-second steps accumulate to
        // 0.9999999999999999 rather than 1.0 — requiring exact equality would
        // be a coin flip on rounding. "Clearly waited, and not indefinitely"
        // is the property that matters.
        self::assertGreaterThan(0.5, $clock->elapsedSeconds());
        self::assertLessThan(2.0, $clock->elapsedSeconds());
    }

    public function test_a_missing_session_is_refused_before_docker_is_asked_to_create_anything(): void
    {
        // A fresh world with no volume: this session does not exist.
        [$docker, , , $runner] = $this->mount(createSession: false);

        try {
            $runner->run(SessionName::from('ghost'), ExecRequest::fromArray(['cmd' => ['true']]));
            self::fail('a missing session must be refused');
        } catch (NoSuchSession $e) {
            self::assertStringContainsString('no session named "ghost"', $e->getMessage());
            self::assertStringContainsString('create it with POST /v1/sessions', $e->getMessage());
        }

        self::assertSame(
            ['volumeExists'],
            $docker->calledMethods(),
            'the session check comes first: a mistyped name must not create anything',
        );
    }

    public function test_stdin_is_refused_before_anything_is_asked_of_docker(): void
    {
        [$docker, , , $runner] = $this->mount();

        try {
            $runner->run(
                SessionName::from('demo'),
                ExecRequest::fromArray(['cmd' => ['cat'], 'stdin' => "hello\n"]),
            );

            self::fail('stdin must be refused by this build, loudly');
        } catch (StdinUnsupported $e) {
            self::assertStringContainsString('not deliverable yet', $e->getMessage());
            self::assertStringContainsString('a file in the workspace', $e->getMessage(), 'a refusal must say what to do instead');
        }

        self::assertSame([], $docker->calledMethods(), 'nothing is created for a command that could not be run whole');
    }

    public function test_the_container_is_removed_even_when_reading_its_logs_fails(): void
    {
        [$docker, , , $runner] = $this->mount();
        $docker->runToCompletion(0);
        $docker->failNext('containerLogs', DockerRefused::because('log driver hiccup'));

        try {
            $runner->run(SessionName::from('demo'), ExecRequest::fromArray(['cmd' => ['true']]));
            self::fail('the scripted log failure must surface');
        } catch (DockerRefused) {
            // The command's failure is the caller's answer...
        }

        self::assertContains('removeContainer', $docker->calledMethods(), '...and cleanup still happens');
    }

    public function test_the_slot_is_released_when_the_command_fails(): void
    {
        [$docker, $slots, , $runner] = $this->mount();
        $docker->failNext('startContainer', DockerUnavailable::because('the proxy went away'));

        try {
            $runner->run(SessionName::from('demo'), ExecRequest::fromArray(['cmd' => ['true']]));
            self::fail('the scripted start failure must surface');
        } catch (DockerUnavailable) {
            // Expected.
        }

        self::assertSame(2, $slots->available(), 'a failed command must not hold its slot');
    }

    public function test_a_command_that_asked_to_reach_its_session_gets_the_network_created_once(): void
    {
        [$docker, , , $runner] = $this->mount();
        $docker->runToCompletion(0);

        $runner->run(
            SessionName::from('demo'),
            ExecRequest::fromArray(['cmd' => ['true'], 'network' => 'session']),
        );

        self::assertTrue($docker->hasNetwork('ww-net-demo'), 'the session network exists after a command asked for it');
        self::assertSame(
            ['ww.created-by' => 'work-warp', 'ww.session' => 'demo', 'ww.kind' => 'network'],
            $docker->labelsOfNetwork('ww-net-demo'),
        );

        // Second command: the network exists, so it is not created again.
        $docker->resetCalls();
        $runner->run(
            SessionName::from('demo'),
            ExecRequest::fromArray(['cmd' => ['true'], 'network' => 'session']),
        );

        self::assertNotContains('createNetwork', $docker->calledMethods(), 'creating it twice would be a bug, not a refresh');
    }

    public function test_a_command_that_asks_for_nothing_touches_no_network_at_all(): void
    {
        [$docker, , , $runner] = $this->mount();
        $docker->runToCompletion(0);

        $runner->run(
            SessionName::from('demo'),
            ExecRequest::fromArray(['cmd' => ['true']]),
        );

        self::assertNotContains('networkExists', $docker->calledMethods(), 'the default path is hermetic and lazy');
        self::assertNotContains('createNetwork', $docker->calledMethods());
    }

    public function test_the_payload_is_the_one_containerspec_builds_and_the_name_is_the_sessions(): void
    {
        [$docker, , , $runner] = $this->mount();
        $docker->runToCompletion(0);

        $runner->run(
            SessionName::from('demo'),
            ExecRequest::fromArray(['cmd' => ['npm', 'test'], 'timeout' => 900]),
        );

        $create = null;

        foreach ($docker->calls() as $call) {
            if ('createContainer' === $call['method']) {
                $create = $call['args'];
            }
        }

        self::assertNotNull($create);
        self::assertMatchesRegularExpression('/^ww-demo-[0-9a-f]{10}$/', (string) $create['name']);

        /** @var array<string, mixed> $payload */
        $payload = $create['payload'];
        self::assertSame('digitaladapt/work-warp:test-base', $payload['Image']);
        self::assertSame(['npm', 'test'], $payload['Cmd']);

        /** @var array<string, mixed> $hostConfig */
        $hostConfig = $payload['HostConfig'];
        self::assertSame('none', $hostConfig['NetworkMode']);
        self::assertSame(['ww-ws-demo:/workspace:rw'], $hostConfig['Binds']);

        /** @var array<string, string> $labels */
        $labels = $payload['Labels'];
        self::assertSame('1200', $labels['ww.ttl'], '900s of command plus the 300s grace');
    }

    public function test_the_log_cap_comes_from_the_limits_not_from_the_caller(): void
    {
        [$docker, , , $runner] = $this->mount();
        $docker->runToCompletion(0);

        $runner->run(SessionName::from('demo'), ExecRequest::fromArray(['cmd' => ['true']]));

        foreach ($docker->calls() as $call) {
            if ('containerLogs' === $call['method']) {
                self::assertSame(1_048_576, $call['args']['maxBytes']);

                return;
            }
        }

        self::fail('the logs were never read');
    }

    /**
     * One test's small world: the scripted daemon, a fresh slot pool, the fake
     * clock, and a runner over all three. The session exists unless a test
     * says otherwise, so most tests are about the command rather than the
     * session.
     *
     * @return array{ScriptedDockerApi, Slots, FakeClock, ExecRunner}
     */
    private function mount(bool $createSession = true): array
    {
        $docker = new ScriptedDockerApi();
        $slots = Slots::of(2, new InMemoryStore());
        $clock = new FakeClock();
        $runner = new ExecRunner($docker, $slots, new NullLogger(), $clock, 'digitaladapt/work-warp:test-base', 300);

        if ($createSession) {
            $docker->createVolume('ww-ws-demo', ['ww.session' => 'demo']);
        }

        $docker->resetCalls();

        return [$docker, $slots, $clock, $runner];
    }
}
