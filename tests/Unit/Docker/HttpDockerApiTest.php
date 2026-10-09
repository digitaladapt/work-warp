<?php

declare(strict_types=1);

namespace App\Tests\Unit\Docker;

use App\Docker\DockerRefused;
use App\Docker\DockerUnavailable;
use App\Docker\HttpDockerApi;
use Generator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The Docker client, measured against a mock that records requests exactly.
 *
 * Every assertion is about a request the broker *built* — the URL, the body,
 * the query — or about how the client reads what came back. No daemon is
 * involved, and that is not a compromise: CI has none, and the pieces worth
 * testing here are exactly the pieces that must not depend on one.
 *
 * @internal
 */
final class HttpDockerApiTest extends TestCase
{
    private const string HOST = 'tcp://lyra-dockerproxy:2375';

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    public function test_it_creates_a_container_from_the_payload_it_was_given(): void
    {
        $api = $this->api($this->client(static fn (): MockResponse => new MockResponse('{"Id":"abc123"}', ['http_code' => 201])));

        $id = $api->createContainer('ww-demo-abc123', ['Image' => 'workwarp-base:test', 'Cmd' => ['true']]);

        self::assertSame('abc123', $id);
        self::assertCount(1, $this->requests);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame(
            'http://lyra-dockerproxy:2375/containers/create?name=ww-demo-abc123',
            $this->requests[0]['url'],
        );
        self::assertSame(
            ['Image' => 'workwarp-base:test', 'Cmd' => ['true']],
            json_decode((string) $this->requests[0]['options']['body'], true, flags: \JSON_THROW_ON_ERROR),
        );
        self::assertContains(
            'Content-Type: application/json',
            $this->requests[0]['options']['normalized_headers']['content-type'] ?? [],
        );
    }

    public function test_a_created_container_without_an_id_is_a_refusal(): void
    {
        $api = $this->api($this->client(static fn (): MockResponse => new MockResponse('{"Warnings":[]}', ['http_code' => 201])));

        try {
            $api->createContainer('ww-demo-abc123', []);
            self::fail('a create without an id must not pass silently');
        } catch (DockerRefused $e) {
            self::assertStringContainsString('reported no id', $e->getMessage());
        }
    }

    public function test_a_refusal_carries_the_daemons_own_words(): void
    {
        $api = $this->api($this->client(static fn (): MockResponse => new MockResponse(
            '{"message":"No such image: workwarp-base:test"}',
            ['http_code' => 404],
        )));

        try {
            $api->createContainer('ww-demo-abc123', []);
            self::fail('a refused create must not pass silently');
        } catch (DockerRefused $e) {
            self::assertStringContainsString('create the container', $e->getMessage());
            self::assertStringContainsString('404', $e->getMessage());
            self::assertStringContainsString('No such image: workwarp-base:test', $e->getMessage());
        }
    }

    public function test_a_daemon_that_does_not_answer_is_unavailable_not_refused(): void
    {
        $api = $this->api($this->client(static fn (): MockResponse => new MockResponse('', ['error' => 'Connection refused'])));

        $this->expectException(DockerUnavailable::class);
        $this->expectExceptionMessageMatches('/lost the Docker daemon/');

        $api->volumeExists('ww-ws-refactor');
    }

    public function test_starting_an_already_started_container_is_not_an_error(): void
    {
        $api = $this->api($this->client(static fn (): MockResponse => new MockResponse('', ['http_code' => 304])));

        $api->startContainer('abc123');

        self::assertSame('http://lyra-dockerproxy:2375/containers/abc123/start', $this->requests[0]['url']);
    }

    public function test_a_refused_start_names_the_action_and_the_reason(): void
    {
        $api = $this->api($this->client(static fn (): MockResponse => new MockResponse(
            '{"message":"driver failed"}',
            ['http_code' => 500],
        )));

        try {
            $api->startContainer('abc123');
            self::fail('a refused start must not pass silently');
        } catch (DockerRefused $e) {
            self::assertStringContainsString('start the container (500)', $e->getMessage());
            self::assertStringContainsString('driver failed', $e->getMessage());
        }
    }

    public function test_inspection_reduces_the_daemons_answer_to_running_or_stopped(): void
    {
        $running = $this->api($this->client(static fn (): MockResponse => new MockResponse('{"State":{"Running":true}}')));

        $status = $running->inspectContainer('abc123');

        self::assertTrue($status->running);
        self::assertNull($status->exitCode);
        self::assertSame('http://lyra-dockerproxy:2375/containers/abc123/json', $this->requests[0]['url']);

        $stopped = $this->api($this->client(static fn (): MockResponse => new MockResponse('{"State":{"Running":false,"ExitCode":7}}')));

        $status = $stopped->inspectContainer('abc123');

        self::assertFalse($status->running);
        self::assertSame(7, $status->exitCode);
    }

    public function test_an_inspection_without_a_state_block_is_a_refusal(): void
    {
        $api = $this->api($this->client(static fn (): MockResponse => new MockResponse('{}')));

        $this->expectException(DockerRefused::class);
        $this->expectExceptionMessageMatches('/no State block/');

        $api->inspectContainer('abc123');
    }

    public function test_killing_a_container_that_is_already_gone_or_stopped_is_not_an_error(): void
    {
        /** @var list<MockResponse> $responses */
        $responses = [new MockResponse('', ['http_code' => 404]), new MockResponse('', ['http_code' => 409])];

        $api = $this->api($this->client(static function () use (&$responses): MockResponse {
            return array_shift($responses) ?? throw new RuntimeException('the script ran out of responses');
        }));

        $api->killContainer('abc123');
        $api->killContainer('abc123');

        self::assertSame('http://lyra-dockerproxy:2375/containers/abc123/kill', $this->requests[0]['url']);
    }

    public function test_removing_a_container_forces_and_tolerates_a_missing_one(): void
    {
        $api = $this->api($this->client(static fn (): MockResponse => new MockResponse('', ['http_code' => 404])));

        $api->removeContainer('abc123');

        self::assertSame('DELETE', $this->requests[0]['method']);
        self::assertSame('http://lyra-dockerproxy:2375/containers/abc123?force=1', $this->requests[0]['url']);
    }

    public function test_logs_come_back_demultiplexed(): void
    {
        $body = self::frame(1, "hello\n").self::frame(2, "warn\n").self::frame(1, "again\n");

        $api = $this->api($this->client(static fn (): MockResponse => new MockResponse($body, ['http_code' => 200])));

        $logs = $api->containerLogs('abc123', 1024);

        self::assertSame("hello\nagain\n", $logs->stdout);
        self::assertSame("warn\n", $logs->stderr);
        self::assertFalse($logs->truncated);
        self::assertSame(
            'http://lyra-dockerproxy:2375/containers/abc123/logs?stdout=1&stderr=1',
            $this->requests[0]['url'],
        );
    }

    public function test_a_frame_split_across_chunks_is_reassembled(): void
    {
        $frame = self::frame(1, 'hello');

        $body = (static function () use ($frame): Generator {
            yield substr($frame, 0, 3);
            yield substr($frame, 3, 4);
            yield substr($frame, 7);
            yield self::frame(2, 'err');
        })();

        $api = $this->api($this->client(static fn (): MockResponse => new MockResponse($body, ['http_code' => 200])));

        $logs = $api->containerLogs('abc123', 1024);

        self::assertSame('hello', $logs->stdout);
        self::assertSame('err', $logs->stderr);
        self::assertFalse($logs->truncated);
    }

    public function test_the_output_caps_per_stream_and_says_when_it_cut(): void
    {
        $body = self::frame(1, 'aaaa').self::frame(2, 'bbbb').self::frame(2, 'cccc').self::frame(1, 'dddd');

        $api = $this->api($this->client(static fn (): MockResponse => new MockResponse($body, ['http_code' => 200])));

        $logs = $api->containerLogs('abc123', 6);

        self::assertSame('aaaa', $logs->stdout, 'a stream that fits under the cap is untouched');
        self::assertSame('bbbbcc', $logs->stderr, 'the capped stream keeps exactly the first six bytes that fit');
        self::assertTrue($logs->truncated);
    }

    public function test_a_log_stream_that_is_not_the_documented_format_is_refused(): void
    {
        $api = $this->api($this->client(static fn (): MockResponse => new MockResponse(
            \chr(9)."\x00\x00\x00".pack('N', 1).'x',
            ['http_code' => 200],
        )));

        $this->expectException(DockerRefused::class);
        $this->expectExceptionMessageMatches('/multiplexed/');

        $api->containerLogs('abc123', 1024);
    }

    public function test_a_frame_that_declares_absurd_size_is_refused_rather_than_buffered(): void
    {
        $api = $this->api($this->client(static fn (): MockResponse => new MockResponse(
            \chr(1)."\x00\x00\x00".pack('N', 5_000_000),
            ['http_code' => 200],
        )));

        $this->expectException(DockerRefused::class);
        $this->expectExceptionMessageMatches('/beyond the/');

        $api->containerLogs('abc123', 1024);
    }

    public function test_a_lost_connection_keeps_the_output_already_read(): void
    {
        $body = (static function (): Generator {
            yield self::frame(1, 'partial');

            throw new TransportException('connection reset');
        })();

        $api = $this->api($this->client(static fn (): MockResponse => new MockResponse($body, ['http_code' => 200])));

        $logs = $api->containerLogs('abc123', 1024);

        self::assertSame('partial', $logs->stdout);
        self::assertTrue($logs->truncated, 'output that stopped early is not the whole output');
    }

    public function test_a_log_read_that_lost_the_daemon_before_anything_arrived_is_unavailable(): void
    {
        $api = $this->api($this->client(static fn (): MockResponse => new MockResponse('', ['error' => 'Connection reset'])));

        $this->expectException(DockerUnavailable::class);
        $this->expectExceptionMessageMatches('/Connection reset/');

        $api->containerLogs('abc123', 1024);
    }

    public function test_a_cap_below_one_byte_is_a_programming_error(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->api(new MockHttpClient(new MockResponse('')))->containerLogs('abc123', 0);
    }

    public function test_network_lookup_maps_200_and_404_to_a_question_answered(): void
    {
        $found = $this->api($this->client(static fn (): MockResponse => new MockResponse('{"Name":"ww-net-refactor"}')));

        self::assertTrue($found->networkExists('ww-net-refactor'));
        self::assertSame('http://lyra-dockerproxy:2375/networks/ww-net-refactor', $this->requests[0]['url']);

        $missing = $this->api($this->client(static fn (): MockResponse => new MockResponse('{"message":"no such network"}', ['http_code' => 404])));

        self::assertFalse($missing->networkExists('ww-net-refactor'));

        $angry = $this->api($this->client(static fn (): MockResponse => new MockResponse('{"message":"daemon is unhappy"}', ['http_code' => 500])));

        try {
            $angry->networkExists('ww-net-refactor');
            self::fail('a lookup the daemon refused must not be read as "no"');
        } catch (DockerRefused $e) {
            self::assertStringContainsString('look up the session network', $e->getMessage());
        }
    }

    public function test_it_creates_a_session_network_with_the_labels_it_was_given(): void
    {
        $api = $this->api($this->client(static fn (): MockResponse => new MockResponse('{"Id":"net1"}', ['http_code' => 201])));

        $api->createNetwork('ww-net-refactor', [
            'ww.created-by' => 'work-warp',
            'ww.session' => 'refactor',
            'ww.kind' => 'network',
        ]);

        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('http://lyra-dockerproxy:2375/networks/create', $this->requests[0]['url']);
        self::assertSame(
            [
                'Name' => 'ww-net-refactor',
                'Labels' => [
                    'ww.created-by' => 'work-warp',
                    'ww.session' => 'refactor',
                    'ww.kind' => 'network',
                ],
            ],
            json_decode((string) $this->requests[0]['options']['body'], true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    public function test_a_refused_network_create_keeps_the_daemons_words(): void
    {
        $api = $this->api($this->client(static fn (): MockResponse => new MockResponse(
            '{"message":"network with name ww-net-refactor already exists"}',
            ['http_code' => 409],
        )));

        try {
            $api->createNetwork('ww-net-refactor', []);
            self::fail('a refused create must not pass silently');
        } catch (DockerRefused $e) {
            self::assertStringContainsString('create the session network', $e->getMessage());
            self::assertStringContainsString('already exists', $e->getMessage());
        }
    }

    public function test_it_creates_a_workspace_volume_with_the_labels_it_was_given(): void
    {
        $api = $this->api($this->client(static fn (): MockResponse => new MockResponse('{"Name":"ww-ws-refactor"}', ['http_code' => 201])));

        $api->createVolume('ww-ws-refactor', [
            'ww.created-by' => 'work-warp',
            'ww.session' => 'refactor',
            'ww.kind' => 'workspace',
        ]);

        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('http://lyra-dockerproxy:2375/volumes/create', $this->requests[0]['url']);
        self::assertSame(
            [
                'Name' => 'ww-ws-refactor',
                'Labels' => [
                    'ww.created-by' => 'work-warp',
                    'ww.session' => 'refactor',
                    'ww.kind' => 'workspace',
                ],
            ],
            json_decode((string) $this->requests[0]['options']['body'], true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    public function test_volume_lookup_maps_200_and_404_to_a_question_answered(): void
    {
        $found = $this->api($this->client(static fn (): MockResponse => new MockResponse('{"Name":"ww-ws-refactor"}')));

        self::assertTrue($found->volumeExists('ww-ws-refactor'));
        self::assertSame('http://lyra-dockerproxy:2375/volumes/ww-ws-refactor', $this->requests[0]['url']);

        $missing = $this->api($this->client(static fn (): MockResponse => new MockResponse('{"message":"no such volume"}', ['http_code' => 404])));

        self::assertFalse($missing->volumeExists('ww-ws-refactor'));

        $angry = $this->api($this->client(static fn (): MockResponse => new MockResponse('{"message":"daemon is unhappy"}', ['http_code' => 500])));

        try {
            $angry->volumeExists('ww-ws-refactor');
            self::fail('a lookup the daemon refused must not be read as "no"');
        } catch (DockerRefused $e) {
            self::assertStringContainsString('look up the workspace volume', $e->getMessage());
        }
    }

    public function test_a_tcp_address_is_normalised_and_a_trailing_slash_is_trimmed(): void
    {
        $api = $this->api(
            $this->client(static fn (): MockResponse => new MockResponse('', ['http_code' => 404])),
            'tcp://lyra-dockerproxy:2375/',
        );

        $api->volumeExists('ww-ws-refactor');

        self::assertSame('http://lyra-dockerproxy:2375/volumes/ww-ws-refactor', $this->requests[0]['url']);
    }

    /**
     * A host the broker cannot dial must be refused *by name*, before anything
     * leaves the process — and the unix-socket case is refused loudest of all,
     * because reaching the daemon socket directly is the exact thing the proxy
     * exists to prevent (SECURITY.md).
     */
    #[DataProvider('rejectedHosts')]
    public function test_a_host_that_is_not_a_tcp_or_http_address_is_refused_by_name(string $host, string $expected): void
    {
        $api = $this->api(new MockHttpClient(new MockResponse('{}')), $host);

        try {
            $api->volumeExists('ww-ws-refactor');
            self::fail('a host that cannot be dialled must be refused before any request');
        } catch (DockerUnavailable $e) {
            self::assertStringContainsString($expected, $e->getMessage());
        }

        self::assertSame([], $this->requests, 'nothing may leave the broker for an unusable host');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejectedHosts(): iterable
    {
        yield 'unix socket' => ['unix:///var/run/docker.sock', 'unix socket'];
        yield 'empty' => ['', 'not set'];
        yield 'no scheme' => ['lyra-dockerproxy:2375', 'must be a tcp://'];
        yield 'wrong scheme' => ['ftp://lyra-dockerproxy:2375', 'must be a tcp://'];
    }

    /**
     * @param callable(string, string): MockResponse $responder
     */
    private function client(callable $responder): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options) use ($responder): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return $responder($method, $url);
        });
    }

    private function api(MockHttpClient $client, string $host = self::HOST): HttpDockerApi
    {
        return new HttpDockerApi($client, $host);
    }

    /**
     * One frame of Docker's multiplexed log format:
     * `[stream(1), pad(3), size(4 big-endian)]` followed by the payload.
     */
    private static function frame(int $stream, string $payload): string
    {
        return \chr($stream)."\x00\x00\x00".pack('N', \strlen($payload)).$payload;
    }
}
