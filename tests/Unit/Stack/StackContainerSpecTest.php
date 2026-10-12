<?php

declare(strict_types=1);

namespace App\Tests\Unit\Stack;

use App\Docker\Labels;
use App\Docker\Limits;
use App\Session\SessionName;
use App\Stack\StackContainerSpec;
use App\Stack\StackRequest;
use PHPUnit\Framework\TestCase;

/**
 * The stack's create payload, and the design principle as a test: what cannot
 * be expressed cannot be forwarded.
 *
 * This is `ContainerSpecTest`'s argument at the stack altitude. The named
 * words are the `HostConfig` fields that turn a container into host root or a
 * host path — and none of them appears anywhere in the payload, not as a key
 * and not as a value. The *second* containment class is asserted too, honestly:
 * a stack service does not drop all capabilities or freeze its rootfs, because
 * every real service image needs to start as root and write where its image
 * expects — and the tests below pin exactly what it gets instead, so the
 * difference is a documented posture rather than a gap.
 *
 * @internal
 */
final class StackContainerSpecTest extends TestCase
{
    public function test_the_payload_cannot_mention_anything_that_would_widen_the_container(): void
    {
        $encoded = json_encode($this->payload(), \JSON_THROW_ON_ERROR);

        $mustNotAppear = [
            'Privileged', 'CapAdd', 'Devices', 'DeviceCgroupRules', 'Sysctls',
            'PidMode', 'IpcMode', 'UTSMode', 'UsernsMode', 'CgroupParent',
            'RestartPolicy', 'Runtime', 'Mounts', 'VolumesFrom', 'PublishAllPorts',
            'PortBindings', 'ExposedPorts', 'ExtraHosts', 'DNS',
        ];

        foreach ($mustNotAppear as $absent) {
            self::assertStringNotContainsString($absent, $encoded, \sprintf('%s must not be expressible', $absent));
        }

        self::assertStringNotContainsString('"host"', $encoded, 'no host network, ever');
        self::assertStringNotContainsString('/var/run/docker.sock', $encoded, 'the socket is not a mount point');
        self::assertStringNotContainsString('CapDrop', $encoded, 'not even CapDrop: the second containment class is stated by what IS there, not by a partial denylist');
    }

    public function test_the_structural_containments_are_all_there(): void
    {
        $hostConfig = $this->payload()['HostConfig'];

        self::assertTrue($hostConfig['Init'], 'PID 1 reaps');
        self::assertFalse($hostConfig['AutoRemove'], 'the broker removes; the daemon must not do it first');
        self::assertSame(['no-new-privileges:true'], $hostConfig['SecurityOpt'], 'setuid binaries gain nothing');
        self::assertSame((new Limits())->pidsLimit, $hostConfig['PidsLimit']);
        self::assertSame((new Limits())->memoryBytes, $hostConfig['Memory']);
        self::assertSame((new Limits())->stopTimeout, $hostConfig['StopTimeout']);
        self::assertSame('ww-net-demo', $hostConfig['NetworkMode'], 'always the session network; there is no other value');
    }

    /**
     * The reachability mechanism, and the reason PLAN §9's port landmine is
     * dissolved rather than fixed: services are found by name on the session
     * network, and no port is ever published anywhere.
     */
    public function test_the_service_is_aliased_on_the_session_network_and_publishes_nothing(): void
    {
        $payload = $this->payload();

        self::assertSame(
            ['ww-net-demo' => ['Aliases' => ['web']]],
            $payload['NetworkingConfig']['EndpointsConfig'],
        );
    }

    public function test_mounts_are_computed_from_stack_volumes_and_the_caller_cannot_add_another(): void
    {
        // Attempts to smuggle a second mount or a host path through unknown
        // fields: none of them is read, and the computed list is the only one.
        $payload = $this->payload([
            'mounts' => [
                ['volume' => 'app-data', 'target' => '/app/data'],
                ['volume' => 'pgdata', 'target' => '/var/lib/postgresql/data', 'read_only' => true],
            ],
            'volumes' => ['/:/host'],
            'binds' => ['/var/run/docker.sock:/sock'],
            'mount' => ['type' => 'bind', 'source' => '/', 'target' => '/host'],
        ]);

        self::assertSame(
            [
                'ww-stk-demo-app-data:/app/data:rw',
                'ww-stk-demo-pgdata:/var/lib/postgresql/data:ro',
            ],
            $payload['HostConfig']['Binds'],
        );

        $encoded = json_encode($payload, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('/host', $encoded);
        self::assertStringNotContainsString('/sock', $encoded);
    }

    public function test_an_empty_command_is_omitted_so_the_images_own_cmd_wins(): void
    {
        $payload = $this->payload();

        self::assertArrayNotHasKey('Cmd', $payload, 'sending [] would override the image command with nothing');

        $withCommand = $this->payload(['command' => ['frankenphp', 'run']]);
        self::assertSame(['frankenphp', 'run'], $withCommand['Cmd']);
    }

    public function test_a_health_command_becomes_the_healthcheck_and_its_absence_omits_it(): void
    {
        self::assertArrayNotHasKey('Healthcheck', $this->payload());

        $payload = $this->payload(['health_cmd' => ['pg_isready', '-U', 'postgres']]);
        $health = $payload['Healthcheck'];

        self::assertSame(['CMD', 'pg_isready', '-U', 'postgres'], $health['Test'], 'CMD form, never CMD-SHELL — arguments are never re-parsed');
        self::assertIsInt($health['Interval']);
        self::assertIsInt($health['Timeout']);
        self::assertIsInt($health['StartPeriod']);
        self::assertIsInt($health['Retries']);
    }

    public function test_the_environment_is_what_the_caller_declared_plus_the_brokers_own(): void
    {
        $payload = $this->payload(['environment' => ['POSTGRES_PASSWORD' => 'x']]);

        self::assertSame(
            [
                'POSTGRES_PASSWORD=x',
                'WW_SESSION=demo',
                'WW_STACK_SERVICE=web',
                'PAGER=cat',
                'GIT_PAGER=cat',
                'TERM=dumb',
                'NO_COLOR=1',
                'CI=1',
            ],
            $payload['Env'],
        );
    }

    public function test_the_labels_are_the_brokers_stamp_including_the_service_and_the_ttl(): void
    {
        $payload = $this->payload();

        self::assertSame(
            [
                'ww.created-by' => 'work-warp',
                'ww.session' => 'demo',
                'ww.kind' => Labels::KIND_STACK,
                'ww.service' => 'web',
                'ww.ttl' => '2100',
            ],
            $payload['Labels'],
        );
    }

    /**
     * @param array<string, mixed> $service
     *
     * @return array<string, mixed>
     */
    private function payload(array $service = []): array
    {
        $request = StackRequest::fromArray([
            'action' => 'up',
            'services' => [
                'web' => ['image' => 'nginx:alpine'] + $service,
            ],
        ]);

        return StackContainerSpec::payload(
            $request->services['web'],
            SessionName::from('demo'),
            'ww-net-demo',
            new Limits(),
            2100,
        );
    }
}
