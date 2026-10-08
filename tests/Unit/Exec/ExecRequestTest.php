<?php

declare(strict_types=1);

namespace App\Tests\Unit\Exec;

use App\Exec\ExecRequest;
use App\Exec\InvalidExecRequest;
use App\Exec\NetworkMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ExecRequestTest extends TestCase
{
    public function test_the_smallest_possible_request_gets_the_documented_defaults(): void
    {
        $request = ExecRequest::fromArray(['cmd' => ['true']]);

        self::assertSame(['true'], $request->cmd);
        self::assertSame(ExecRequest::DEFAULT_TIMEOUT, $request->timeout);
        self::assertSame(NetworkMode::None, $request->network, 'the default must be the hermetic one');
        self::assertSame('/workspace', $request->workdir->absolute());
        self::assertSame([], $request->env, 'nothing is inherited, so nothing arrives unannounced');
        self::assertNull($request->stdin);
    }

    public function test_the_command_survives_as_an_argv_array(): void
    {
        // The reason `cmd` is a list and not a string: nothing re-parses it, so
        // quoting can never reinterpret an argument. This is the property that a
        // scalar-only schema would have taken away (DOCKER-ACCESS.md §5a).
        $cmd = ['sh', '-c', 'printf %s "$HOME" && echo "$(whoami)"'];

        $request = ExecRequest::fromArray(['cmd' => $cmd]);

        self::assertSame($cmd, $request->cmd);
        self::assertCount(3, $request->cmd);
    }

    /**
     * The exact regression a "scalar-only" API would reintroduce: a command line
     * quoted into one string, which only works if something downstream parses it.
     */
    public function test_a_string_command_is_refused_with_a_message_showing_the_array_form(): void
    {
        try {
            ExecRequest::fromArray(['cmd' => 'cd packages/api && npm test']);
            self::fail('a string command must be refused');
        } catch (InvalidExecRequest $e) {
            self::assertStringContainsString('cmd must be a list of strings', $e->getMessage());
            self::assertStringContainsString('["npm","test"]', $e->getMessage());
        }
    }

    public function test_an_empty_command_is_refused(): void
    {
        $this->expectException(InvalidExecRequest::class);
        ExecRequest::fromArray(['cmd' => []]);
    }

    #[DataProvider('nonStringCommandProvider')]
    public function test_a_command_holding_a_non_string_is_refused(mixed $argument): void
    {
        $this->expectException(InvalidExecRequest::class);
        ExecRequest::fromArray(['cmd' => ['echo', $argument]]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonStringCommandProvider(): iterable
    {
        yield 'integer' => [3];
        yield 'null' => [null];
        yield 'array' => [['nested']];
        yield 'boolean' => [true];
    }

    public function test_an_empty_command_argument_is_refused(): void
    {
        $this->expectException(InvalidExecRequest::class);
        ExecRequest::fromArray(['cmd' => ['echo', '']]);
    }

    public function test_a_command_argument_containing_a_null_byte_is_refused(): void
    {
        $this->expectException(InvalidExecRequest::class);
        ExecRequest::fromArray(['cmd' => ["echo\0hidden"]]);
    }

    public function test_the_default_timeout_is_used_when_none_is_given(): void
    {
        self::assertSame(
            ExecRequest::DEFAULT_TIMEOUT,
            ExecRequest::fromArray(['cmd' => ['true']])->timeout,
        );
    }

    public function test_the_timeout_bounds_are_inclusive(): void
    {
        self::assertSame(1, ExecRequest::fromArray(['cmd' => ['true'], 'timeout' => 1])->timeout);
        self::assertSame(ExecRequest::MAX_TIMEOUT, ExecRequest::fromArray(['cmd' => ['true'], 'timeout' => ExecRequest::MAX_TIMEOUT])->timeout);
    }

    #[DataProvider('badTimeoutProvider')]
    public function test_a_timeout_outside_its_bounds_is_refused(mixed $timeout): void
    {
        $this->expectException(InvalidExecRequest::class);
        ExecRequest::fromArray(['cmd' => ['true'], 'timeout' => $timeout]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badTimeoutProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'past the maximum' => [ExecRequest::MAX_TIMEOUT + 1];
        yield 'a float' => [1.5];
        yield 'words' => ['soon'];
        yield 'null-ish string' => [''];
    }

    public function test_a_json_number_arrives_as_a_string_and_is_still_accepted(): void
    {
        // JSON bodies decoded without strict types yield strings freely; the
        // broker reads its own input rather than relying on a content type.
        self::assertSame(900, ExecRequest::fromArray(['cmd' => ['true'], 'timeout' => '900'])->timeout);
    }

    public function test_the_only_accepted_networks_are_none_and_session(): void
    {
        self::assertSame(NetworkMode::None, ExecRequest::fromArray(['cmd' => ['true'], 'network' => 'none'])->network);
        self::assertSame(NetworkMode::Session, ExecRequest::fromArray(['cmd' => ['true'], 'network' => 'session'])->network);
    }

    #[DataProvider('refusedNetworkProvider')]
    public function test_any_other_network_is_refused_rather_than_ignored(mixed $network): void
    {
        // "host" is refused for the obvious reason; so is an arbitrary network
        // name, which is how a caller would look for the Docker proxy's network.
        $this->expectException(InvalidExecRequest::class);
        ExecRequest::fromArray(['cmd' => ['true'], 'network' => $network]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function refusedNetworkProvider(): iterable
    {
        yield 'host' => ['host'];
        yield 'bridge' => ['bridge'];
        yield 'the proxy network by name' => ['lyra_backend'];
        yield 'the session network spelled out' => ['ww-net-demo'];
        yield 'empty' => [''];
        yield 'not a string' => [1];
    }

    public function test_a_relative_workdir_inside_the_workspace_is_accepted(): void
    {
        self::assertSame(
            '/workspace/packages/api',
            ExecRequest::fromArray(['cmd' => ['npm', 'test'], 'workdir' => 'packages/api'])->workdir->absolute(),
        );
    }

    public function test_an_absolute_workdir_is_refused_rather_than_reinterpreted(): void
    {
        // Refused, not normalised: silently rewriting /etc into
        // /workspace/etc would look like the boundary held when the caller was
        // asking for something else entirely.
        try {
            ExecRequest::fromArray(['cmd' => ['true'], 'workdir' => '/etc']);
            self::fail('an absolute workdir must be refused');
        } catch (InvalidExecRequest $e) {
            self::assertStringContainsString('must be relative to the workspace', $e->getMessage());
        }
    }

    public function test_a_workdir_that_escapes_the_workspace_is_refused(): void
    {
        try {
            ExecRequest::fromArray(['cmd' => ['true'], 'workdir' => '../../etc']);
            self::fail('an escaping workdir must be refused');
        } catch (InvalidExecRequest $e) {
            self::assertStringContainsString('must not escape the workspace', $e->getMessage());
        }
    }

    public function test_a_workdir_that_escapes_in_the_middle_is_refused_too(): void
    {
        $this->expectException(InvalidExecRequest::class);
        ExecRequest::fromArray(['cmd' => ['true'], 'workdir' => 'a/../../b']);
    }

    public function test_declared_environment_variables_are_preserved(): void
    {
        $request = ExecRequest::fromArray([
            'cmd' => ['npm', 'test'],
            'env' => ['NODE_ENV' => 'test', '_PRIVATE' => '1'],
        ]);

        self::assertSame(['NODE_ENV' => 'test', '_PRIVATE' => '1'], $request->env);
    }

    #[DataProvider('badEnvNameProvider')]
    public function test_an_environment_key_that_is_not_a_shell_name_is_refused(string $name): void
    {
        $this->expectException(InvalidExecRequest::class);
        ExecRequest::fromArray(['cmd' => ['true'], 'env' => [$name => 'value']]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badEnvNameProvider(): iterable
    {
        yield 'leading digit' => ['1X'];
        yield 'a dash' => ['A-B'];
        yield 'empty' => [''];
        yield 'a space' => ['A B'];
        yield 'a dot' => ['A.B'];
    }

    public function test_the_brokers_own_environment_namespace_is_reserved(): void
    {
        // Otherwise a request could declare WW_SESSION and quietly become a
        // different principal.
        try {
            ExecRequest::fromArray(['cmd' => ['true'], 'env' => ['WW_SESSION' => 'someone-else']]);
            self::fail('the WW_ namespace must be reserved');
        } catch (InvalidExecRequest $e) {
            self::assertStringContainsString('reserved for the broker', $e->getMessage());
        }
    }

    public function test_a_non_string_environment_value_is_refused(): void
    {
        $this->expectException(InvalidExecRequest::class);
        ExecRequest::fromArray(['cmd' => ['true'], 'env' => ['NODE_ENV' => 1]]);
    }

    public function test_too_many_environment_variables_are_refused(): void
    {
        $env = [];

        for ($i = 0; $i <= ExecRequest::MAX_ENV_VARS; ++$i) {
            $env['VAR_'.$i] = 'x';
        }

        $this->expectException(InvalidExecRequest::class);
        ExecRequest::fromArray(['cmd' => ['true'], 'env' => $env]);
    }

    public function test_an_oversized_environment_value_is_refused(): void
    {
        $this->expectException(InvalidExecRequest::class);
        ExecRequest::fromArray([
            'cmd' => ['true'],
            'env' => ['BIG' => str_repeat('x', ExecRequest::MAX_ENV_VALUE_BYTES + 1)],
        ]);
    }

    public function test_stdin_is_optional_and_capped(): void
    {
        self::assertSame("line\n", ExecRequest::fromArray(['cmd' => ['cat'], 'stdin' => "line\n"])->stdin);

        $this->expectException(InvalidExecRequest::class);
        ExecRequest::fromArray([
            'cmd' => ['cat'],
            'stdin' => str_repeat('x', ExecRequest::MAX_STDIN_BYTES + 1),
        ]);
    }

    /**
     * The refusal list, as one statement: keys that describe *what the container
     * is* are not merely ignored, they cannot survive into the request — so
     * nothing downstream can accidentally honour them.
     */
    public function test_fields_that_describe_the_container_are_not_part_of_the_request(): void
    {
        $request = ExecRequest::fromArray([
            'cmd' => ['true'],
            'privileged' => true,
            'mounts' => [['source' => '/', 'target' => '/host']],
            'cap_add' => ['SYS_ADMIN'],
            'devices' => ['/dev/sda'],
            'user' => '0:0',
            'entrypoint' => ['/bin/sh'],
            'hostname' => 'lyra-terminal',
            'labels' => ['ww.session' => 'someone-else'],
            'network_mode' => 'host',
        ]);

        self::assertSame(['true'], $request->cmd);
        self::assertSame(NetworkMode::None, $request->network);
        self::assertSame([], $request->env);
    }
}
