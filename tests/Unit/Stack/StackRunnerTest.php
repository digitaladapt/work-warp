<?php

declare(strict_types=1);

namespace App\Tests\Unit\Stack;

use App\Docker\DockerRefused;
use App\Docker\PullOutput;
use App\Exec\Slots;
use App\Session\NoSuchSession;
use App\Session\SessionName;
use App\Session\SessionNetwork;
use App\Stack\StackRequest;
use App\Stack\StackRunner;
use App\Tests\Support\FakeClock;
use App\Tests\Support\ScriptedDockerApi;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * The bring-up lifecycle: session check, slot, network, per-service ensure
 * (volumes, image, recreate), and the dependency waits.
 *
 * Everything Docker is scripted and time is the fake clock, so the waits that
 * would take real seconds run in microseconds. What is asserted is the order
 * of the calls and the results composed from them — the same standard as the
 * rest of the suite.
 *
 * @internal
 */
final class StackRunnerTest extends TestCase
{
    public function test_a_simple_stack_comes_up_in_order_and_reports_its_services(): void
    {
        [$docker, , $runner] = $this->mount();
        $docker->scriptNextPull(new PullOutput('', false, false, false));

        $result = $this->upWith($runner, ['web' => ['image' => 'nginx:alpine']]);

        self::assertNull($result->failure);
        self::assertFalse($result->timedOut);
        self::assertCount(1, $result->services);
        self::assertSame('web', $result->services[0]->name);
        self::assertSame('ww-demo-web-1', $result->services[0]->container);
        self::assertTrue($result->services[0]->running);

        self::assertSame(
            ['volumeExists', 'networkExists', 'createNetwork', 'listContainers', 'imageExists', 'pullImage', 'imageExists', 'createContainer', 'startContainer', 'inspectContainer'],
            $docker->calledMethods(),
            'the bring-up order: session, network, one listing, ensure image, recreate',
        );
    }

    public function test_a_present_image_is_never_pulled(): void
    {
        // The `build` then `up` case: the scratch image is already there, and
        // a pull would be both slow and wrong (the registry does not have it).
        [$docker, , $runner] = $this->mount();
        $docker->seedImage('ww-demo-app:latest');

        $result = $this->upWith($runner, ['web' => ['image' => 'ww-demo-app:latest']]);

        self::assertNull($result->failure);
        self::assertNotContains('pullImage', $docker->calledMethods());
    }

    public function test_a_pull_that_produces_nothing_is_a_failure_on_that_service(): void
    {
        // The silent-failure case: the stream carried no error line, but the
        // image is not there afterwards. Text is never trusted for the
        // outcome — imageExists is the authority.
        [$docker, , $runner] = $this->mount();
        $docker->scriptNextPull(new PullOutput('', false, false, false), produces: false);

        $result = $this->upWith($runner, ['web' => ['image' => 'nginx:alpine']]);

        self::assertNotNull($result->failure);
        self::assertSame('web', $result->failure['service']);
        self::assertStringContainsString('did not produce the image', $result->failure['reason']);
        self::assertSame([], $result->services, 'nothing was created for a service whose image never arrived');
    }

    public function test_a_pull_error_line_is_included_in_the_failure(): void
    {
        [$docker, , $runner] = $this->mount();
        $docker->scriptNextPull(
            new PullOutput("error: manifest unknown\n", false, false, true),
            produces: false,
        );

        $result = $this->upWith($runner, ['web' => ['image' => 'ghcr.io/nope/nothing:v9']]);

        self::assertNotNull($result->failure);
        self::assertStringContainsString('manifest unknown', $result->failure['reason']);
    }

    public function test_dependencies_start_before_their_dependents(): void
    {
        [$docker, , $runner] = $this->mount();
        $docker->scriptNextPull(new PullOutput('', false, false, false));
        $docker->scriptNextPull(new PullOutput('', false, false, false));

        $result = $this->upWith($runner, [
            'web' => ['image' => 'nginx:alpine', 'depends_on' => ['db']],
            'db' => ['image' => 'postgres:17-alpine'],
        ]);

        self::assertNull($result->failure);
        self::assertSame(['db', 'web'], [$result->services[0]->name, $result->services[1]->name]);

        $creates = array_values(array_filter(
            $docker->calls(),
            static fn (array $call): bool => 'createContainer' === $call['method'],
        ));

        $names = array_map(static fn (array $call): string => $call['args']['name'], $creates);
        self::assertSame(['ww-demo-db-1', 'ww-demo-web-1'], $names, 'db is created before web');
    }

    public function test_a_completed_dependency_that_exited_nonzero_fails_the_dependent(): void
    {
        [$docker, $clock, $runner] = $this->mount();

        // The migrate service runs and exits 1 on its first inspection.
        // (Overriding the mount's linger, which suits the long-running web.)
        $docker->runToCompletion(1);
        $docker->scriptNextPull(new PullOutput('', false, false, false));

        $result = $this->upWith($runner, [
            'web' => ['image' => 'nginx:alpine', 'depends_on' => ['migrate' => ['condition' => 'completed']]],
            'migrate' => ['image' => 'nginx:alpine'],
        ]);

        self::assertNotNull($result->failure);
        self::assertSame('web', $result->failure['service']);
        self::assertStringContainsString('exited with code 1', $result->failure['reason']);
    }

    public function test_a_missing_session_is_refused_before_anything_is_asked_of_docker(): void
    {
        [$docker, , $runner] = $this->mount(createSession: false);

        try {
            $this->upWith($runner, ['web' => ['image' => 'nginx:alpine']]);
            self::fail('a missing session must be refused');
        } catch (NoSuchSession $e) {
            self::assertStringContainsString('no session named "demo"', $e->getMessage());
        }

        self::assertSame(['volumeExists'], $docker->calledMethods());
    }

    public function test_the_slot_is_released_when_the_bring_up_fails(): void
    {
        [$docker, $slots, $runner] = $this->mount();
        $docker->failNext('createContainer', DockerRefused::because('name conflict'));

        $result = $this->upWith($runner, ['web' => ['image' => 'nginx:alpine']]);

        self::assertNotNull($result->failure);
        self::assertStringContainsString('name conflict', $result->failure['reason']);
        self::assertSame(2, $slots->available(), 'a failed bring-up must not hold its slot');
    }

    public function test_down_removes_stack_containers_and_volumes_but_not_the_workspace(): void
    {
        [$docker, , $runner] = $this->mount();

        // The workspace volume — not a stack container, and must survive.
        $docker->seedContainer('c1', 'ww-demo-web-1', true, 1000, [
            'ww.created-by' => 'work-warp', 'ww.session' => 'demo', 'ww.kind' => 'stack', 'ww.service' => 'web',
        ]);
        $docker->seedContainer('c2', 'ww-demo-db-1', true, 1000, [
            'ww.created-by' => 'work-warp', 'ww.session' => 'demo', 'ww.kind' => 'stack', 'ww.service' => 'db',
        ]);
        // A neighbour: same daemon, different session. Must survive.
        $docker->seedContainer('c3', 'lyra-terminal', true, 1000, []);

        $docker->seedVolume('ww-stk-demo-pgdata', [
            'ww.created-by' => 'work-warp', 'ww.session' => 'demo', 'ww.kind' => 'stack-volume',
        ]);
        $docker->seedVolume('ww-ws-demo', [
            'ww.created-by' => 'work-warp', 'ww.session' => 'demo', 'ww.kind' => 'workspace',
        ]);

        $result = $this->downWith($runner);

        self::assertSame(['ww-demo-web-1', 'ww-demo-db-1'], $result->containers);
        self::assertSame(['ww-stk-demo-pgdata'], $result->volumes, 'the workspace volume is not a stack volume');
        self::assertSame([], $result->failures);

        self::assertFalse($docker->hasVolume('ww-stk-demo-pgdata'));
        self::assertTrue($docker->hasVolume('ww-ws-demo'), 'the workspace survives a stack teardown');
    }

    public function test_down_with_keep_volumes_leaves_the_data(): void
    {
        [$docker, , $runner] = $this->mount();
        $docker->seedVolume('ww-stk-demo-pgdata', [
            'ww.created-by' => 'work-warp', 'ww.session' => 'demo', 'ww.kind' => 'stack-volume',
        ]);

        $result = $this->downWith($runner, keepVolumes: true);

        self::assertSame([], $result->volumes);
        self::assertTrue($docker->hasVolume('ww-stk-demo-pgdata'));
    }

    public function test_logs_assemble_every_service_with_headers(): void
    {
        [$docker, , $runner] = $this->mount();

        $docker->seedContainer('c1', 'ww-demo-web-1', true, 1000, [
            'ww.created-by' => 'work-warp', 'ww.session' => 'demo', 'ww.kind' => 'stack', 'ww.service' => 'web',
        ]);
        $docker->seedContainer('c2', 'ww-demo-db-1', true, 1000, [
            'ww.created-by' => 'work-warp', 'ww.session' => 'demo', 'ww.kind' => 'stack', 'ww.service' => 'db',
        ]);

        // The script's log reader returns the same output for whichever
        // container is asked; the assembly and headers are what this tests.
        $docker->scriptNextLogs(new \App\Docker\LogOutput("hello\n", ''));
        $docker->scriptNextLogs(new \App\Docker\LogOutput("hello\n", ''));

        $result = $this->logsWith($runner);

        self::assertStringContainsString('==> db <==', $result->output);
        self::assertStringContainsString('==> web <==', $result->output);
        self::assertFalse($result->truncated);
    }

    /**
     * @param array<string, mixed> $services
     */
    private function upWith(StackRunner $runner, array $services): \App\Stack\UpResult
    {
        return $runner->up(SessionName::from('demo'), StackRequest::fromArray([
            'action' => 'up',
            'services' => $services,
        ]));
    }

    private function downWith(StackRunner $runner, bool $keepVolumes = false): \App\Stack\DownResult
    {
        return $runner->down(SessionName::from('demo'), StackRequest::fromArray([
            'action' => 'down',
            'keep_volumes' => $keepVolumes,
        ]));
    }

    private function logsWith(StackRunner $runner): \App\Stack\LogsResult
    {
        return $runner->logs(SessionName::from('demo'), 1048576);
    }

    /**
     * @return array{ScriptedDockerApi, Slots, StackRunner, FakeClock}
     */
    private function mount(bool $createSession = true): array
    {
        $docker = new ScriptedDockerApi();
        $slots = Slots::of(2, new InMemoryStore());
        $clock = new FakeClock();
        $runner = new StackRunner($docker, $slots, new SessionNetwork($docker), new NullLogger(), $clock);

        // A stack service is a long-running process, not a command: started
        // containers linger (the run script's `finish` would mark every
        // service exited the moment it started, which is what a test for the
        // completed-dependency path opts back into explicitly).
        $docker->linger();

        if ($createSession) {
            $docker->createVolume('ww-ws-demo', ['ww.session' => 'demo']);
        }

        $docker->resetCalls();

        return [$docker, $slots, $runner, $clock];
    }
}
