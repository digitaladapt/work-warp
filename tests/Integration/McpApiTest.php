<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\ScriptedDockerApi;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The MCP surface, end to end through the real kernel and the real gate:
 * the handshake, `tools/list`, and a `tools/call` that actually reaches the
 * command runner — with Docker scripted, exactly as the rest of the suite runs.
 *
 * The properties worth holding:
 *
 * - **It is under the gate.** `/v1/mcp` answers `401` without a token and
 *   `401` with a wrong one — the same answer as every other `/v1` path —
 *   because this endpoint can start stacks and run commands and an
 *   unauthenticated one would be remote command execution.
 * - **The handshake is real.** `initialize` returns an `Mcp-Session-Id`, and a
 *   `tools/call` in a later request (as a client really does) is served.
 * - **The tool list is the catalogue's**, including the annotations that say
 *   which tools are destructive.
 * - **A refusal keeps the broker's words**: `run_command` against a session
 *   that does not exist answers with the same sentence the HTTP endpoint uses,
 *   not a generic internal error.
 * - **The package is served**: `ww://pkg` as a resource and `GET /v1/pkg`.
 *
 * @internal
 */
final class McpApiTest extends WebTestCase
{
    private const string TOKEN = 'test-token';

    private const string PROTOCOL = '2025-03-26';

    public function test_the_mcp_endpoint_is_behind_the_bearer_gate(): void
    {
        $client = self::createClient();
        $docker = $this->docker();
        $docker->resetCalls();

        $client->request('POST', '/v1/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
        self::assertSame('Bearer', $client->getResponse()->headers->get('WWW-Authenticate'));
        self::assertSame([], $docker->calledMethods(), 'an unauthenticated MCP request must not reach the server');
    }

    public function test_initialize_returns_a_session_id(): void
    {
        $client = self::createClient();

        $sessionId = $this->initialize($client);

        self::assertNotSame('', $sessionId);
    }

    public function test_tools_list_returns_the_catalogue_with_annotations(): void
    {
        $client = self::createClient();
        $sessionId = $this->initialize($client);

        $this->call($client, $sessionId, 'tools/list', null, 'list');

        $body = $this->jsonRpc($client);
        $tools = $body['result']['tools'] ?? [];

        self::assertIsArray($tools);

        $names = array_column($tools, 'name');

        foreach (['run_command', 'quick_command', 'create_session', 'list_sessions', 'get_session', 'ps', 'delete_session', 'build_image', 'stack', 'reap'] as $expected) {
            self::assertContains($expected, $names, 'the catalogue must be the tool list');
        }

        $delete = $tools[array_search('delete_session', $names, true)];

        self::assertTrue($delete['annotations']['destructiveHint'] ?? null, 'delete_session must be marked destructive');

        $list = $tools[array_search('list_sessions', $names, true)];

        self::assertTrue($list['annotations']['readOnlyHint'] ?? null, 'list_sessions must be marked read-only');

        // run_command requires cmd; quick_command does not.
        $run = $tools[array_search('run_command', $names, true)];
        self::assertContains('cmd', $run['inputSchema']['required'] ?? []);

        $quick = $tools[array_search('quick_command', $names, true)];
        self::assertNotContains('cmd', $quick['inputSchema']['required'] ?? []);
    }

    public function test_quick_command_runs_in_the_session_and_returns_the_command_shape(): void
    {
        $client = self::createClient();
        $docker = $this->docker();
        $docker->createVolume('ww-ws-demo', ['ww.session' => 'demo']);
        $docker->runToCompletion(0);
        $docker->scriptNextLogs(new \App\Docker\LogOutput("hello\n", ''));

        $sessionId = $this->initialize($client);
        $docker->resetCalls();

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'quick_command',
            'arguments' => ['session' => 'demo', 'cmd' => ['echo', 'hello']],
        ], 'call');

        $body = $this->jsonRpc($client);

        self::assertFalse($body['result']['isError'] ?? true, 'a command that ran is not an error');

        // The result's text content carries the JSON the tool returned.
        $text = $body['result']['content'][0]['text'] ?? '';
        $decoded = json_decode($text, true);

        self::assertIsArray($decoded, 'the tool result must be the command JSON: '.$text);
        self::assertSame(0, $decoded['exit_code']);
        self::assertSame("hello\n", $decoded['stdout']);
        self::assertFalse($decoded['truncated']);
        self::assertFalse($decoded['timed_out']);

        self::assertContains('createContainer', $docker->calledMethods(), 'the tool reached the real runner');
    }

    public function test_a_refusal_keeps_the_brokers_own_words(): void
    {
        $client = self::createClient();
        $sessionId = $this->initialize($client);

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'run_command',
            'arguments' => ['session' => 'nope', 'cmd' => ['true']],
        ], 'call');

        $body = $this->jsonRpc($client);

        // NoSuchSession is caller-facing, so it is rendered as a tool error
        // carrying the broker's sentence rather than a generic -32603.
        $text = $body['result']['content'][0]['text'] ?? '';
        self::assertStringContainsString('no session named "nope"', $text);
        self::assertTrue($body['result']['isError'] ?? false);
    }

    public function test_invalid_arguments_are_refused_before_the_handler_runs(): void
    {
        $client = self::createClient();
        $docker = $this->docker();
        $docker->createVolume('ww-ws-demo', ['ww.session' => 'demo']);
        $docker->resetCalls();

        $sessionId = $this->initialize($client);

        $this->call($client, $sessionId, 'tools/call', [
            'name' => 'run_command',
            'arguments' => ['session' => 'demo', 'cmd' => []],
        ], 'call');

        $body = $this->jsonRpc($client);

        // The SDK validates against the schema (minItems: 1) and answers an
        // invalid-params error, so nothing reached Docker.
        self::assertArrayHasKey('error', $body, 'empty cmd must fail schema validation: '.json_encode($body));
        self::assertSame([], $docker->calledMethods());
    }

    public function test_the_package_manifest_is_served_as_a_resource(): void
    {
        $client = self::createClient();
        $sessionId = $this->initialize($client);

        $this->call($client, $sessionId, 'resources/read', ['uri' => 'ww://pkg'], 'pkg');

        $body = $this->jsonRpc($client);
        $text = $body['result']['contents'][0]['text'] ?? '';
        $manifest = json_decode($text, true);

        self::assertIsArray($manifest, 'the resource must carry the manifest JSON: '.$text);
        self::assertSame('work-warp', $manifest['name']);
        self::assertSame('/v1/mcp', $manifest['endpoint']);
        self::assertSame('bearer', $manifest['auth']['scheme']);
        self::assertCount(10, $manifest['tools'], 'the manifest lists the same catalogue the server serves');
    }

    public function test_the_manifest_is_also_plain_http(): void
    {
        $client = self::createClient();

        $client->request('GET', '/v1/pkg', server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN]);

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        $body = $this->decode($client);

        self::assertSame('work-warp', $body['name']);
        self::assertSame('/v1/mcp', $body['endpoint']);
        self::assertCount(10, $body['tools']);
    }

    public function test_the_tool_list_over_http_matches_the_manifest(): void
    {
        $client = self::createClient();

        $client->request('GET', '/v1/pkg/tools', server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN]);

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $body = $this->decode($client);

        self::assertCount(10, $body['tools']);
        self::assertContains('run_command', array_column($body['tools'], 'name'));
    }

    #[DataProvider('gatedPaths')]
    public function test_every_package_path_needs_the_token(string $method, string $path): void
    {
        $client = self::createClient();

        $client->request($method, $path);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode(), $path);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function gatedPaths(): iterable
    {
        yield 'manifest' => ['GET', '/v1/pkg'];
        yield 'tools' => ['GET', '/v1/pkg/tools'];
    }

    /**
     * `initialize`, as a real client does it, and the session id it returns.
     *
     * `disableReboot()` is load-bearing, and only in tests: a real deployment
     * shares state across requests the way the protocol requires (the session
     * lives in a cache pool, the workspace lives in Docker), while the default
     * `KernelBrowser` boots a fresh container per request — which would hand
     * each request a fresh `ScriptedDockerApi` and make "create a session, then
     * use it" untestable. The scripted double *is* the daemon here, so it has
     * to outlive the request the way a daemon does.
     */
    private function initialize(KernelBrowser $client): string
    {
        $client->disableReboot();

        $client->request('POST', '/v1/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
        ], content: json_encode([
            'jsonrpc' => '2.0',
            'id' => 'init',
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::PROTOCOL,
                'capabilities' => new stdClass(),
                'clientInfo' => ['name' => 'work-warp-test', 'version' => '1.0'],
            ],
        ], \JSON_THROW_ON_ERROR));

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode(), 'initialize must succeed');

        $sessionId = $client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertNotNull($sessionId, 'initialize must return an Mcp-Session-Id header');

        // Complete the handshake the way the protocol requires.
        $this->raw($client, $sessionId, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        return $sessionId;
    }

    /**
     * @param array<string, mixed>|null $params
     */
    private function call(KernelBrowser $client, string $sessionId, string $method, ?array $params, string $id): void
    {
        $payload = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];

        if (null !== $params) {
            $payload['params'] = $params;
        }

        $this->raw($client, $sessionId, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function raw(KernelBrowser $client, string $sessionId, array $payload): void
    {
        $client->request('POST', '/v1/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
            'HTTP_MCP_SESSION_ID' => $sessionId,
        ], content: json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    /**
     * The JSON-RPC payload of the last response, whether it came back as JSON
     * or as a single SSE `data:` frame — the transport chooses, and a test that
     * assumed one would be testing the transport rather than the server.
     *
     * @return array<string, mixed>
     */
    private function jsonRpc(KernelBrowser $client): array
    {
        $content = (string) $client->getResponse()->getContent();

        if (str_starts_with(trim($content), 'event:') || str_contains($content, 'data:')) {
            foreach (preg_split('/\R/', $content) ?: [] as $line) {
                if (str_starts_with($line, 'data:')) {
                    $content = trim(substr($line, 5));
                    break;
                }
            }
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(KernelBrowser $client): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function docker(): ScriptedDockerApi
    {
        $docker = self::getContainer()->get(ScriptedDockerApi::class);
        self::assertInstanceOf(ScriptedDockerApi::class, $docker);

        return $docker;
    }
}
