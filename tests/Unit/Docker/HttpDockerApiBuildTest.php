<?php

declare(strict_types=1);

namespace App\Tests\Unit\Docker;

use App\Docker\DockerRefused;
use App\Docker\DockerUnavailable;
use App\Docker\HttpDockerApi;
use Generator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The build reader, measured against a mock that records requests exactly.
 *
 * The classifications the reader makes were each derived from a live daemon
 * (Docker 29.8) and are asserted here so a well-meaning edit cannot quietly
 * change them:
 *
 * - a `200` stream carrying `{"error":…}` is a failed *build*, not an HTTP
 *   failure;
 * - an idle timeout ends the stream and means the broker's clock cut it;
 * - a refused request (bad query) is an HTTP 5xx and leaves as
 *   `DockerRefused`.
 *
 * Every stream fixture is built with `json_encode` rather than hand-written
 * escape sequences — a raw newline inside a JSON string is not JSON, and a
 * reader that drops it is correct while a test expecting it is not.
 *
 * @internal
 */
final class HttpDockerApiBuildTest extends TestCase
{
    private const string HOST = 'tcp://lyra-dockerproxy:2375';

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    public function test_the_build_request_carries_the_query_the_context_and_the_budget(): void
    {
        $api = $this->api($this->client(static fn (): MockResponse => new MockResponse(
            self::line(['stream' => "Step 1/2 : FROM alpine\n"])
            .self::line(['stream' => "Successfully built abc123\n"]),
            ['http_code' => 200],
        )));

        $output = $api->buildImage(
            ['t' => 'ww-demo-intl:latest', 'version' => '1'],
            'the tar bytes',
            900.0,
            1024,
        );

        self::assertSame("Step 1/2 : FROM alpine\nSuccessfully built abc123\n", $output->output);
        self::assertFalse($output->failed);
        self::assertFalse($output->timedOut);
        self::assertFalse($output->truncated);

        self::assertSame('POST', $this->requests[0]['method']);
        self::assertStringStartsWith('http://lyra-dockerproxy:2375/build?', $this->requests[0]['url']);
        self::assertContains(
            'Content-Type: application/x-tar',
            $this->requests[0]['options']['normalized_headers']['content-type'] ?? [],
        );
        self::assertSame('the tar bytes', $this->requests[0]['options']['body']);
        self::assertSame(900.0, $this->requests[0]['options']['timeout'], 'the budget is the connection\'s idle timeout');
        self::assertSame(900.0, $this->requests[0]['options']['max_duration'], 'and its absolute ceiling');
    }

    /**
     * Measured: the daemon reports a failed build inside a `200` stream. It is
     * a result, and the daemon's own words must survive to the caller — that
     * is the entire diagnosis they get.
     */
    public function test_a_reported_error_is_a_failed_build_not_an_http_failure(): void
    {
        $api = $this->api($this->client(static fn (): MockResponse => new MockResponse(
            self::line(['stream' => "Step 1/1 : FROM alpine\n"])
            .self::line(['error' => 'unshare: operation not permitted']),
            ['http_code' => 200],
        )));

        $output = $api->buildImage(['t' => 'ww-demo-x:latest'], 'tar', 60.0, 1024);

        self::assertTrue($output->failed);
        self::assertStringContainsString('Step 1/1', $output->output);
        self::assertStringContainsString('error: unshare: operation not permitted', $output->output);
        self::assertFalse($output->timedOut);
    }

    public function test_the_stream_keeps_stream_and_status_and_drops_aux(): void
    {
        $api = $this->api($this->client(static fn (): MockResponse => new MockResponse(
            self::line(['stream' => "kept\n"])
            .self::line(['status' => 'Step 1/2'])
            .self::line(['aux' => ['ID' => 'sha256:deadbeef']])
            ."not json at all\n",
            ['http_code' => 200],
        )));

        $output = $api->buildImage(['t' => 'x'], 'tar', 60.0, 1024);

        self::assertSame("kept\nStep 1/2\n", $output->output, 'aux and non-JSON lines are dropped, not fatal');
    }

    /**
     * The daemon answers a bad query with HTTP 500 and its own message —
     * measured for malformed `labels` and invalid tag forms. That is a
     * refusal, so it leaves as `DockerRefused` (the broker's 502).
     */
    public function test_a_refused_build_request_keeps_the_daemons_words(): void
    {
        $api = $this->api($this->client(static fn (): MockResponse => new MockResponse(
            '{"message":"error reading labels: invalid character \'o\' in literal null (expecting \'u\')"}',
            ['http_code' => 500],
        )));

        $this->expectException(DockerRefused::class);
        $this->expectExceptionMessageMatches('/run the build \\(500\\).*error reading labels/s');

        $api->buildImage(['t' => 'x'], 'tar', 60.0, 1024);
    }

    /**
     * Measured: an idle timeout yields an `isTimeout()` chunk and the stream
     * ends. That is the broker's own clock cutting the build — the daemon
     * cancels a build whose client disconnects, which is the only stop this
     * endpoint has.
     */
    public function test_an_idle_timeout_is_a_cut_build_with_the_output_kept(): void
    {
        $body = (static function (): Generator {
            yield self::line(['stream' => "Step 1/2\n"]);
            yield ''; // MockResponse's way of simulating an idle timeout
        })();

        $api = $this->api($this->client(static fn (): MockResponse => new MockResponse($body, ['http_code' => 200])));

        $output = $api->buildImage(['t' => 'x'], 'tar', 5.0, 1024);

        self::assertTrue($output->timedOut);
        self::assertSame("Step 1/2\n", $output->output, 'partial output beats none');
    }

    public function test_hitting_the_output_cap_truncates_without_killing_the_build(): void
    {
        $response = new MockResponse(
            (static function (): Generator {
                yield self::line(['stream' => str_repeat('a', 64)."\n"]);
                yield self::line(['stream' => str_repeat('b', 64)."\n"]);
                yield self::line(['stream' => str_repeat('c', 64)."\n"]);
            })(),
            ['http_code' => 200],
        );

        $api = $this->api($this->client(static fn (): MockResponse => $response));

        $output = $api->buildImage(['t' => 'x'], 'tar', 60.0, 70);

        self::assertTrue($output->truncated);
        self::assertStringContainsString(str_repeat('a', 64), $output->output);
        self::assertStringNotContainsString(str_repeat('b', 64), $output->output);
        self::assertFalse(
            $response->getInfo('canceled'),
            'a capped log must not cancel the build: cancelling disconnects the client, which is how the daemon cancels a build',
        );
    }

    public function test_a_lost_connection_with_output_keeps_the_output(): void
    {
        $body = (static function (): Generator {
            yield self::line(['stream' => "partial\n"]);

            throw new TransportException('connection reset');
        })();

        $api = $this->api($this->client(static fn (): MockResponse => new MockResponse($body, ['http_code' => 200])));

        $output = $api->buildImage(['t' => 'x'], 'tar', 60.0, 1024);

        self::assertSame("partial\n", $output->output);
        self::assertTrue($output->truncated, 'a read that stopped early is not the whole output');
        self::assertFalse($output->timedOut, 'a reset connection is not the clock; the runner\'s image lookup still answers the real question');
    }

    public function test_a_lost_connection_with_nothing_read_is_unavailable(): void
    {
        $api = $this->api($this->client(static fn (): MockResponse => new MockResponse('', ['error' => 'Failed to connect'])));

        $this->expectException(DockerUnavailable::class);
        $this->expectExceptionMessageMatches('/Failed to connect/');

        $api->buildImage(['t' => 'x'], 'tar', 60.0, 1024);
    }

    public function test_a_cap_below_one_byte_is_a_programming_error(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->api(new MockHttpClient(new MockResponse('{}')))->buildImage(['t' => 'x'], 'tar', 60.0, 0);
    }

    /**
     * The context reaches the seam as the caller's stream, unmodified
     * (measured): a resource stays the same resource — not copied, not
     * buffered. That matters because `ContextStream` has already attached its
     * counting wrapper and the seam must not detach it by materialising the
     * body.
     */
    public function test_the_context_is_forwarded_as_the_callers_stream_unmodified(): void
    {
        $api = $this->api($this->client(static fn (): MockResponse => new MockResponse('{}', ['http_code' => 200])));

        $resource = fopen('php://temp', 'r+');
        self::assertIsResource($resource);
        fwrite($resource, 'the tar bytes');
        rewind($resource);

        $api->buildImage(['t' => 'x'], $resource, 60.0, 1024);

        self::assertSame($resource, $this->requests[0]['options']['body'], 'the same resource, not a copy');
    }

    /**
     * And a generator is normalised to a callable — Symfony *streaming* it
     * rather than loading it into memory, which is the property the cap
     * depends on: a body buffered up front would defeat `ContextStream`.
     */
    public function test_a_generator_context_is_streamed_not_materialised(): void
    {
        $api = $this->api($this->client(static fn (): MockResponse => new MockResponse('{}', ['http_code' => 200])));

        $generator = (static function (): Generator { yield 'chunk-one'; })();

        $api->buildImage(['t' => 'x'], $generator, 60.0, 1024);

        self::assertTrue(
            \is_callable($this->requests[0]['options']['body']),
            'a generator is streamed through a callable, not materialised into a string',
        );
    }

    public function test_image_exists_maps_200_and_404_to_a_question_answered(): void
    {
        $found = $this->api($this->client(static fn (): MockResponse => new MockResponse('{"Id":"sha256:abc"}')));

        self::assertTrue($found->imageExists('ww-demo-intl:latest'));
        self::assertSame(
            'http://lyra-dockerproxy:2375/images/ww-demo-intl%3Alatest/json',
            $this->requests[0]['url'],
            'the reference is one encoded path segment',
        );

        $missing = $this->api($this->client(static fn (): MockResponse => new MockResponse('{"message":"No such image: ww-demo-intl:latest"}', ['http_code' => 404])));

        self::assertFalse($missing->imageExists('ww-demo-intl:latest'));
    }

    public function test_an_image_lookup_the_daemon_errored_on_is_a_refusal_not_a_no(): void
    {
        $api = $this->api($this->client(static fn (): MockResponse => new MockResponse(
            '{"message":"daemon is unhappy"}',
            ['http_code' => 500],
        )));

        try {
            $api->imageExists('ww-demo-intl:latest');
            self::fail('a lookup the daemon refused must not be read as "no"');
        } catch (DockerRefused $e) {
            self::assertStringContainsString('look up the built image', $e->getMessage());
        }
    }

    /**
     * One build progress line, encoded for a stream fixture.
     *
     * @param array<string, mixed> $payload
     */
    private static function line(array $payload): string
    {
        return json_encode($payload, \JSON_THROW_ON_ERROR)."\n";
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
}
