<?php

declare(strict_types=1);

namespace App\Tests\Unit\Docker;

use App\Docker\ContainerSpec;
use App\Docker\Limits;
use App\Exec\ExecRequest;
use App\Session\SessionName;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ContainerSpecTest extends TestCase
{
    public function test_the_top_level_payload_holds_exactly_the_expected_keys(): void
    {
        // A closed set, asserted rather than described: a key added here without
        // thinking about it should fail a test, because every key is a thing the
        // caller can influence by some path.
        self::assertSame(
            ['Image', 'Cmd', 'WorkingDir', 'Env', 'Labels', 'Tty', 'OpenStdin', 'StdinOnce', 'HostConfig'],
            array_keys($this->payload(ExecRequest::fromArray(['cmd' => ['true']]))),
        );
    }

    public function test_the_host_config_holds_exactly_the_expected_keys(): void
    {
        self::assertSame(
            [
                'Init', 'AutoRemove', 'CapDrop', 'SecurityOpt', 'ReadonlyRootfs', 'Tmpfs',
                'PidsLimit', 'Memory', 'NanoCpus', 'NetworkMode', 'Binds', 'StopTimeout', 'User',
            ],
            array_keys($this->payload(ExecRequest::fromArray(['cmd' => ['true']]))['HostConfig']),
        );
    }

    /**
     * The design principle, as a test: what cannot be expressed cannot be
     * forwarded. These are the `HostConfig` fields from DOCKER-ACCESS.md §7 that
     * turn a container into host root or a host path, and none of their names
     * appears anywhere in the payload — not as a key, not as a value.
     */
    public function test_the_payload_cannot_mention_anything_that_would_widen_the_container(): void
    {
        $encoded = json_encode($this->payload(ExecRequest::fromArray(['cmd' => ['true'], 'network' => 'session'])), \JSON_THROW_ON_ERROR);

        $mustNotAppear = [
            'Privileged', 'CapAdd', 'Devices', 'DeviceCgroupRules', 'Sysctls',
            'PidMode', 'IpcMode', 'UTSMode', 'UsernsMode', 'CgroupParent',
            'RestartPolicy', 'Runtime', 'Mounts', 'Binds',
        ];

        foreach ($mustNotAppear as $absent) {
            // 'Binds' is asserted separately: the *key* exists because exactly
            // one computed mount is required, and the check here is that no
            // second mount can be described.
            if ('Binds' === $absent) {
                continue;
            }

            self::assertStringNotContainsString($absent, $encoded, \sprintf('%s must not be expressible', $absent));
        }

        self::assertStringNotContainsString('"host"', $encoded, 'no host network, ever');
        self::assertStringNotContainsString('/var/run/docker.sock', $encoded, 'the socket is not a mount point');
    }

    public function test_exactly_one_mount_is_computed_from_the_session_and_the_request_cannot_add_another(): void
    {
        $payload = $this->payload(
            ExecRequest::fromArray([
                'cmd' => ['true'],
                'mounts' => [['source' => '/', 'target' => '/host']],
                'binds' => ['/var/run/docker.sock:/var/run/docker.sock'],
            ]),
        );

        self::assertSame(['ww-ws-demo:/workspace:rw'], $payload['HostConfig']['Binds']);
    }

    public function test_capabilities_are_dropped_and_privilege_escalation_is_denied(): void
    {
        $hostConfig = $this->payload(ExecRequest::fromArray(['cmd' => ['true']]))['HostConfig'];

        self::assertSame(['ALL'], $hostConfig['CapDrop']);
        self::assertSame(['no-new-privileges:true'], $hostConfig['SecurityOpt']);
        self::assertTrue($hostConfig['Init'], 'PID 1 must reap');

        // The broker removes the container itself, after reading the exit code
        // and the logs. `--rm` can delete it before either is read.
        self::assertFalse($hostConfig['AutoRemove']);
    }

    public function test_the_root_filesystem_is_read_only_and_tmp_is_the_only_writable_scratch(): void
    {
        $hostConfig = $this->payload(ExecRequest::fromArray(['cmd' => ['true']]))['HostConfig'];

        self::assertTrue($hostConfig['ReadonlyRootfs']);
        self::assertSame(['/tmp' => 'rw,noexec,nosuid,size=64m'], $hostConfig['Tmpfs']);
    }

    public function test_no_terminal_is_allocated(): void
    {
        // The pager bug from PLAN.md §2.3, refused by construction: a command
        // that believes it has a TTY waits for keystrokes that never come.
        self::assertFalse($this->payload(ExecRequest::fromArray(['cmd' => ['git', 'log']]))['Tty']);
    }

    public function test_stdin_is_opened_only_when_the_request_carries_stdin(): void
    {
        $without = $this->payload(ExecRequest::fromArray(['cmd' => ['true']]));
        self::assertFalse($without['OpenStdin']);
        self::assertFalse($without['StdinOnce']);

        $with = $this->payload(ExecRequest::fromArray(['cmd' => ['cat'], 'stdin' => "hello\n"]));
        self::assertTrue($with['OpenStdin']);
        self::assertTrue($with['StdinOnce']);
    }

    public function test_the_network_defaults_to_none_and_the_session_network_is_named_by_the_broker(): void
    {
        self::assertSame(
            'none',
            $this->payload(ExecRequest::fromArray(['cmd' => ['true']]))['HostConfig']['NetworkMode'],
        );

        self::assertSame(
            'ww-net-demo',
            $this->payload(ExecRequest::fromArray(['cmd' => ['true'], 'network' => 'session']))['HostConfig']['NetworkMode'],
        );
    }

    public function test_the_container_environment_is_exactly_what_was_declared_plus_the_brokers_own(): void
    {
        // Exact equality on purpose. The failure this guards against is
        // inheritance: any variable from the broker's own environment
        // appearing here would be a leak of the same class as
        // /proc/1/environ. The broker's own five — PAGER, GIT_PAGER, TERM,
        // NO_COLOR, CI — are the pager guard (PLAN.md §5.1) and are stamped
        // on every command; the caller cannot collide with them, because
        // their names are refused at request construction.
        $payload = $this->payload(ExecRequest::fromArray([
            'cmd' => ['true'],
            'env' => ['NODE_ENV' => 'test'],
        ]));

        self::assertSame([
            'NODE_ENV=test',
            'WW_SESSION=demo',
            'PAGER=cat',
            'GIT_PAGER=cat',
            'TERM=dumb',
            'NO_COLOR=1',
            'CI=1',
        ], $payload['Env']);
    }

    public function test_a_captured_channel_never_advertises_a_human_terminal(): void
    {
        // The bug from PLAN.md §2.3, refused in two independent layers: no TTY
        // is allocated, and TERM=dumb is set so a command that inspects the
        // environment cannot conclude it is talking to a person. The five
        // variables come from the broker and are asserted as an exact list in
        // the test above; this one asserts the intent.
        $payload = $this->payload(ExecRequest::fromArray(['cmd' => ['git', 'log']]));

        self::assertFalse($payload['Tty']);
        self::assertContains('TERM=dumb', $payload['Env']);
        self::assertContains('PAGER=cat', $payload['Env']);
        self::assertContains('GIT_PAGER=cat', $payload['Env']);
    }

    public function test_the_broker_stamps_its_own_labels_and_owns_the_ttl(): void
    {
        $payload = $this->payload(ExecRequest::fromArray(['cmd' => ['true'], 'timeout' => 900]));

        self::assertSame(
            [
                'ww.created-by' => 'work-warp',
                'ww.session' => 'demo',
                'ww.kind' => 'cmd',
                // timeout + the grace period, so a container the broker failed to
                // reap is still stopped by the janitor.
                'ww.ttl' => '1200',
            ],
            $payload['Labels'],
        );
    }

    public function test_a_caller_cannot_set_its_own_labels(): void
    {
        $payload = $this->payload(ExecRequest::fromArray([
            'cmd' => ['true'],
            'labels' => ['ww.session' => 'someone-else', 'ww.created-by' => 'not-work-warp'],
        ]));

        self::assertSame('demo', $payload['Labels']['ww.session']);
        self::assertSame('work-warp', $payload['Labels']['ww.created-by']);
    }

    public function test_the_command_is_carried_verbatim(): void
    {
        $cmd = ['sh', '-c', 'echo "$WW_SESSION" && ls -la'];

        $payload = $this->payload(ExecRequest::fromArray(['cmd' => $cmd]));

        self::assertSame($cmd, $payload['Cmd'], 'arbitrary as arguments, never re-split');
    }

    public function test_the_workdir_is_absolute_inside_the_volume(): void
    {
        $payload = $this->payload(ExecRequest::fromArray(['cmd' => ['true'], 'workdir' => 'packages/api']));

        self::assertSame('/workspace/packages/api', $payload['WorkingDir']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ExecRequest $request, string $image = 'digitaladapt/work-warp:test-base'): array
    {
        return ContainerSpec::payload(
            $image,
            SessionName::from('demo'),
            $request,
            'ww-ws-demo',
            Limits::defaults(),
        );
    }
}
