<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session;

use App\Docker\DockerRefused;
use App\Session\NoSuchSession;
use App\Session\SessionDestroyer;
use App\Session\SessionName;
use App\Tests\Support\ScriptedDockerApi;
use PHPUnit\Framework\TestCase;

/**
 * The destructive half of the session API: what it removes, in what order,
 * and what it refuses to touch.
 *
 * Docker is scripted, so everything here is about what the destroyer *asks
 * for*: the order (containers → network → volumes → workspace last), the
 * membership rule (labels, never names), the one rule `keep_workspace`
 * follows (every volume or none), and the best-effort behaviour that lets one
 * refusing container be a reported failure rather than a stranded teardown.
 *
 * @internal
 */
final class SessionDestroyerTest extends TestCase
{
    public function test_destroy_removes_containers_then_network_then_volumes_workspace_last(): void
    {
        [$docker, $destroyer] = $this->mount();

        $docker->seedContainer('c1', 'ww-demo-abc123', false, 1000, $this->commandLabels());
        $docker->seedContainer('c2', 'ww-demo-web-1', true, 1000, $this->stackLabels());
        // A neighbour: another session's container, and a container with no
        // session label at all. Both must survive.
        $docker->seedContainer('c3', 'ww-other-abc123', true, 1000, $this->commandLabels('other'));
        $docker->seedContainer('c4', 'lyra-terminal', true, 1000, []);

        $docker->createNetwork('ww-net-demo', $this->networkLabels());

        $docker->seedVolume('ww-stk-demo-pgdata', $this->stackVolumeLabels());
        $docker->seedVolume('ww-stk-other-pgdata', $this->stackVolumeLabels('other'));
        $docker->seedVolume('ww-ws-other', $this->workspaceLabels('other'));

        // The world is set; what follows is the only thing under test.
        $docker->resetCalls();

        $result = $destroyer->destroy(SessionName::from('demo'), keepWorkspace: false);

        self::assertSame(['ww-demo-abc123', 'ww-demo-web-1'], $result->containers);
        self::assertSame(['ww-net-demo'], $result->networks);
        self::assertSame(
            ['ww-stk-demo-pgdata', 'ww-ws-demo'],
            $result->volumes,
            'the session\'s stack data goes, and the workspace goes last',
        );
        self::assertSame([], $result->failures);
        self::assertTrue($result->workspaceRemoved);

        self::assertFalse($docker->hasVolume('ww-ws-demo'));
        self::assertFalse($docker->hasNetwork('ww-net-demo'));
        self::assertFalse($docker->hasVolume('ww-stk-demo-pgdata'));

        self::assertTrue($docker->hasVolume('ww-stk-other-pgdata'), 'another session\'s stack data is out of range');
        self::assertTrue($docker->hasVolume('ww-ws-other'), 'another session\'s workspace is out of range');

        self::assertSame(
            [
                'volumeExists',
                'listContainers',
                'removeContainer',
                'removeContainer',
                'networkExists',
                'removeNetwork',
                'listVolumes',
                'removeVolume',
                'removeVolume',
            ],
            $docker->calledMethods(),
            'the dependency order: containers first (the network cannot go while they are attached), then the network, then the volumes, workspace last',
        );
    }

    public function test_keep_workspace_keeps_every_volume_and_still_removes_containers_and_network(): void
    {
        [$docker, $destroyer] = $this->mount();

        $docker->seedContainer('c1', 'ww-demo-abc123', false, 1000, $this->commandLabels());
        $docker->createNetwork('ww-net-demo', $this->networkLabels());
        $docker->seedVolume('ww-stk-demo-pgdata', $this->stackVolumeLabels());

        // The world is set; what follows is the only thing under test.
        $docker->resetCalls();

        $result = $destroyer->destroy(SessionName::from('demo'), keepWorkspace: true);

        self::assertSame(['ww-demo-abc123'], $result->containers, 'containers are the session\'s live resources and always go');
        self::assertSame(['ww-net-demo'], $result->networks);
        self::assertSame([], $result->volumes, 'keep_workspace keeps every volume: all of them or none of them');
        self::assertSame([], $result->failures);
        self::assertFalse($result->workspaceRemoved);

        self::assertTrue($docker->hasVolume('ww-ws-demo'));
        self::assertTrue($docker->hasVolume('ww-stk-demo-pgdata'));
        self::assertSame(
            ['volumeExists', 'listContainers', 'removeContainer', 'networkExists', 'removeNetwork'],
            $docker->calledMethods(),
            'no volume is asked about, let alone removed',
        );
    }

    public function test_a_session_with_no_workspace_volume_is_not_created_on_the_way_past(): void
    {
        [$docker, $destroyer] = $this->mount(createSession: false);

        try {
            $destroyer->destroy(SessionName::from('demo'), keepWorkspace: false);
            self::fail('deleting a session that does not exist must refuse');
        } catch (NoSuchSession $e) {
            self::assertStringContainsString('no session named "demo"', $e->getMessage());
        }

        self::assertSame(['volumeExists'], $docker->calledMethods());
    }

    public function test_a_container_that_refuses_to_go_is_collected_and_the_teardown_continues(): void
    {
        [$docker, $destroyer] = $this->mount();

        $docker->seedContainer('c1', 'ww-demo-abc123', false, 1000, $this->commandLabels());
        $docker->seedContainer('c2', 'ww-demo-def456', false, 1000, $this->commandLabels());
        $docker->seedVolume('ww-stk-demo-pgdata', $this->stackVolumeLabels());

        $docker->failNext('removeContainer', DockerRefused::because('remove the container (500): device or resource busy'));

        $result = $destroyer->destroy(SessionName::from('demo'), keepWorkspace: false);

        self::assertSame(['ww-demo-def456'], $result->containers, 'the refusing container is not reported as removed');
        self::assertSame(
            [['name' => 'ww-demo-abc123', 'reason' => 'remove the container (500): device or resource busy']],
            $result->failures,
            'the refusal is reported with the daemon\'s words',
        );
        self::assertSame(['ww-stk-demo-pgdata', 'ww-ws-demo'], $result->volumes, 'one refusal does not strand the rest');
        self::assertTrue($result->workspaceRemoved);
    }

    public function test_a_session_that_never_made_a_network_reports_none_removed(): void
    {
        [$docker, $destroyer] = $this->mount();

        $result = $destroyer->destroy(SessionName::from('demo'), keepWorkspace: false);

        self::assertSame([], $result->networks, 'the network is created lazily and may never have existed');
        self::assertSame(['volumeExists', 'listContainers', 'networkExists', 'listVolumes', 'removeVolume'], $docker->calledMethods());
    }

    /**
     * @return array{ScriptedDockerApi, SessionDestroyer}
     */
    private function mount(bool $createSession = true): array
    {
        $docker = new ScriptedDockerApi();
        $destroyer = new SessionDestroyer($docker);

        if ($createSession) {
            $docker->createVolume('ww-ws-demo', $this->workspaceLabels());
        }

        $docker->resetCalls();

        return [$docker, $destroyer];
    }

    /**
     * @return array<string, string>
     */
    private function workspaceLabels(string $session = 'demo'): array
    {
        return ['ww.created-by' => 'work-warp', 'ww.session' => $session, 'ww.kind' => 'workspace'];
    }

    /**
     * @return array<string, string>
     */
    private function networkLabels(): array
    {
        return ['ww.created-by' => 'work-warp', 'ww.session' => 'demo', 'ww.kind' => 'network'];
    }

    /**
     * @return array<string, string>
     */
    private function commandLabels(string $session = 'demo'): array
    {
        return ['ww.created-by' => 'work-warp', 'ww.session' => $session, 'ww.kind' => 'cmd', 'ww.ttl' => '2100'];
    }

    /**
     * @return array<string, string>
     */
    private function stackLabels(string $session = 'demo'): array
    {
        return ['ww.created-by' => 'work-warp', 'ww.session' => $session, 'ww.kind' => 'stack', 'ww.service' => 'web', 'ww.ttl' => '2100'];
    }

    /**
     * @return array<string, string>
     */
    private function stackVolumeLabels(string $session = 'demo'): array
    {
        return ['ww.created-by' => 'work-warp', 'ww.session' => $session, 'ww.kind' => 'stack-volume'];
    }
}
