<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Build\BuildContextTooLarge;
use App\Build\BuildOutput;
use App\Build\BuildRequest;
use App\Build\BuildRunner;
use App\Docker\DockerRefused;
use App\Docker\DockerUnavailable;
use App\Exec\Slots;
use App\Tests\Support\ScriptedDockerApi;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/sessions/{name}/build`, through the real kernel: the gate, the
 * route, the query parsing, the runner, and the status-code mapping — with
 * Docker scripted.
 *
 * The shapes this endpoint refuses are the design's own language, each
 * asserted with the reason it exists: 404 for a mistyped session (never
 * created on the way past), 422 for a tag or parameter that cannot be
 * expressed, 429 when every slot is busy, 413 for a context past the cap,
 * 503/502 for the daemon being unreachable or refusing. A build that ran and
 * failed is **not** an error status: it is a 200 whose body carries the
 * daemon's own diagnosis and `image: null`.
 *
 * @internal
 */
final class BuildApiTest extends WebTestCase
{
    private const string TOKEN = 'test-token';

    public function test_a_build_that_produces_an_image_returns_it_with_the_output(): void
    {
        $client = self::createClient();
        $this->docker()->runBuildToCompletion("Step 1/2\nSuccessfully built abc\n", tagged: true);
        $this->docker()->createVolume('ww-ws-refactor', ['ww.session' => 'refactor']);

        $this->build($client, 'refactor', 'intl', 'the tar bytes');

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $body = $this->decode($client);
        self::assertSame('ww-refactor-intl:latest', $body['image']);
        self::assertSame("Step 1/2\nSuccessfully built abc\n", $body['output']);
        self::assertFalse($body['truncated']);
        self::assertFalse($body['timed_out']);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function test_a_mistyped_session_is_a_404_and_is_not_created_on_the_way_past(): void
    {
        $client = self::createClient();
        $docker = $this->docker();
        $docker->resetCalls();

        $this->build($client, 'refactor', 'intl', 'tar');

        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
        self::assertSame('no-such-session', $this->decode($client)['status']);
        self::assertFalse($docker->hasVolume('ww-ws-refactor'), 'a typo must never become a durable object');
        self::assertSame(['volumeExists'], $docker->calledMethods());
    }

    /**
     * A build that ran and failed is a 200 with `image: null` and the daemon's
     * words — not a 5xx. The difference between "the build failed" and "the
     * broker broke" is exactly the difference the caller needs.
     */
    public function test_a_failed_build_is_a_200_with_the_daemons_diagnosis(): void
    {
        $client = self::createClient();
        $this->docker()->runBuildToCompletion("Step 1/1 : FROM alpine\n", tagged: false, failed: true, error: 'unshare: operation not permitted');
        $this->docker()->createVolume('ww-ws-refactor', ['ww.session' => 'refactor']);

        $this->build($client, 'refactor', 'intl', 'tar');

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $body = $this->decode($client);
        self::assertNull($body['image']);
        self::assertStringContainsString('unshare: operation not permitted', $body['output']);
        self::assertFalse($body['timed_out']);
    }

    public function test_a_build_that_overran_its_budget_is_a_result_not_an_error(): void
    {
        $client = self::createClient();
        $this->docker()->scriptNextBuild(new BuildOutput('', false, true, false), tagged: false);
        $this->docker()->createVolume('ww-ws-refactor', ['ww.session' => 'refactor']);

        $this->build($client, 'refactor', 'intl', 'tar', query: '&timeout=1');

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $body = $this->decode($client);
        self::assertTrue($body['timed_out']);
        self::assertNull($body['image']);
    }

    public function test_a_busy_broker_answers_429_and_says_it_does_not_queue(): void
    {
        $client = self::createClient();
        $this->docker()->createVolume('ww-ws-refactor', ['ww.session' => 'refactor']);

        // .env.test sets WW_MAX_CONCURRENCY=1 and the pool is process-wide
        // (§6b), so a slot held here is a slot the request cannot take — the
        // same technique ExecApiTest uses for the same reason.
        $slots = self::getContainer()->get(Slots::class);
        self::assertInstanceOf(Slots::class, $slots);
        $held = $slots->reserve();

        try {
            $this->build($client, 'refactor', 'intl', 'tar');
        } finally {
            $held->release();
        }

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $client->getResponse()->getStatusCode());
        self::assertSame('busy', $this->decode($client)['status']);
        self::assertStringContainsString('does not queue', (string) $this->decode($client)['reason']);
    }

    public function test_a_tag_or_parameter_that_cannot_be_expressed_is_422(): void
    {
        $client = self::createClient();
        $this->docker()->createVolume('ww-ws-refactor', ['ww.session' => 'refactor']);

        // The daemon itself would refuse these ("invalid reference format"),
        // measured — the endpoint refuses them first so the 422 is the
        // caller's mistake rather than a 502 from the daemon.
        foreach (['WW-Bad', 'x-', 'x..y', 'x:tag', 'a/b'] as $badTag) {
            $this->build($client, 'refactor', $badTag, 'tar');

            self::assertSame(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $client->getResponse()->getStatusCode(),
                \sprintf('tag "%s" must be a 422', $badTag),
            );
            self::assertSame('invalid', $this->decode($client)['status']);
        }

        $this->build($client, 'refactor', 'intl', 'tar', query: '&network=host');
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('network must be one of', (string) $this->decode($client)['reason']);

        // A missing tag is refused too, with the query form shown.
        $this->build($client, 'refactor', null, 'tar');
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('?tag=intl', (string) $this->decode($client)['reason']);
    }

    /**
     * The declared length is a courtesy check — it refuses an oversized upload
     * before it starts. (The authoritative check counts real bytes; a chunked
     * request declares nothing, and a declaration is a claim, not a
     * measurement.).
     */
    public function test_a_declared_context_past_the_cap_is_refused_before_it_is_read(): void
    {
        $client = self::createClient();
        $docker = $this->docker();
        $this->docker()->createVolume('ww-ws-refactor', ['ww.session' => 'refactor']);
        $docker->resetCalls();

        $client->request(
            'POST',
            '/v1/sessions/refactor/build?tag=intl',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/x-tar',
                'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
                'CONTENT_LENGTH' => (string) (BuildRequest::MAX_CONTEXT_BYTES + 1),
            ],
            'x',
        );

        self::assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $client->getResponse()->getStatusCode());

        $body = $this->decode($client);
        self::assertSame('too-large', $body['status']);
        self::assertStringContainsString('134217728-byte cap', (string) $body['reason']);
        self::assertSame([], $docker->calledMethods(), 'nothing is asked of Docker for a context that cannot be carried — not even whether the session exists');
    }

    /**
     * And the streamed trip — the one a declared length cannot catch — maps to
     * the same 413 through the controller's own catch.
     */
    public function test_a_context_that_trips_the_cap_mid_stream_is_413(): void
    {
        $client = self::createClient();
        $docker = $this->docker();
        $this->docker()->createVolume('ww-ws-refactor', ['ww.session' => 'refactor']);

        $docker->failNext('buildImage', BuildContextTooLarge::because(BuildRequest::MAX_CONTEXT_BYTES));

        $this->build($client, 'refactor', 'intl', 'tar');

        self::assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $client->getResponse()->getStatusCode());
        self::assertSame('too-large', $this->decode($client)['status']);
    }

    public function test_a_daemon_that_cannot_be_reached_is_503_and_one_that_refuses_is_502(): void
    {
        $client = self::createClient();
        $client->disableReboot();

        $this->docker()->createVolume('ww-ws-refactor', ['ww.session' => 'refactor']);

        $this->docker()->failNext('buildImage', DockerUnavailable::because('the proxy is not answering'));
        $this->build($client, 'refactor', 'intl', 'tar');
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $client->getResponse()->getStatusCode());

        $this->docker()->failNext('buildImage', DockerRefused::because('error reading labels'));
        $this->build($client, 'refactor', 'intl', 'tar');
        self::assertSame(Response::HTTP_BAD_GATEWAY, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('error reading labels', (string) $this->decode($client)['reason']);
    }

    public function test_output_that_is_not_utf8_is_scrubbed_rather_than_500ing(): void
    {
        $client = self::createClient();
        $this->docker()->runBuildToCompletion("bad\xB1bytes\n", tagged: true);
        $this->docker()->createVolume('ww-ws-refactor', ['ww.session' => 'refactor']);

        $this->build($client, 'refactor', 'intl', 'tar');

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertSame("bad\u{FFFD}bytes\n", $this->decode($client)['output']);
    }

    public function test_the_endpoint_sits_behind_the_same_gate_as_everything_else(): void
    {
        $client = self::createClient();

        $client->request('POST', '/v1/sessions/refactor/build?tag=intl', [], [], ['CONTENT_TYPE' => 'application/x-tar'], 'tar');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
    }

    public function test_the_runner_is_wired_in_the_container(): void
    {
        self::bootKernel();

        self::assertTrue(
            self::getContainer()->has(BuildRunner::class),
            'the build runner must exist in the compiled container',
        );
    }

    private function build(KernelBrowser $client, string $session, ?string $tag, string $content, string $query = ''): void
    {
        $path = '/v1/sessions/'.$session.'/build'.(null !== $tag ? '?tag='.$tag : '').$query;

        $client->request('POST', $path, [], [], [
            'CONTENT_TYPE' => 'application/x-tar',
            'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
        ], $content);
    }

    private function docker(): ScriptedDockerApi
    {
        $docker = self::getContainer()->get(ScriptedDockerApi::class);
        self::assertInstanceOf(ScriptedDockerApi::class, $docker);

        return $docker;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
