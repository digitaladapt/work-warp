<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Docker\DockerRefused;
use App\Docker\DockerUnavailable;
use App\Docker\LogOutput;
use App\Exec\Slots;
use App\Tests\Support\ScriptedDockerApi;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/sessions/{name}/exec`, through the real kernel: the gate, the
 * route, validation, the runner, and the status-code mapping — with Docker
 * scripted and the clock faked, exactly as the unit suite runs them.
 *
 * The status codes are the design's own language and each is asserted here
 * with the reason it exists: 404 for a mistyped session (never created on the
 * way past), 422 for anything that cannot be expressed, 429 when every slot
 * is busy (the broker does not queue), 501 for stdin, 503/502 for the daemon
 * being unreachable or refusing. A timeout is *not* an error code: it ran and
 * was stopped, and the body says so.
 *
 * @internal
 */
final class ExecApiTest extends WebTestCase
{
    private const string TOKEN = 'test-token';

    public function test_a_command_that_completes_returns_its_result(): void
    {
        $client = self::createClient();
        $this->docker()->runToCompletion(0);
        $this->docker()->scriptNextLogs(new LogOutput("PASS\n", ''));
        $this->docker()->createVolume('ww-ws-refactor', ['ww.session' => 'refactor']);

        $this->exec($client, 'refactor', ['cmd' => ['npm', 'test']]);

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $body = $this->decode($client);
        self::assertSame(0, $body['exit_code']);
        self::assertSame("PASS\n", $body['stdout']);
        self::assertSame('', $body['stderr']);
        self::assertFalse($body['truncated']);
        self::assertFalse($body['timed_out']);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function test_a_mistyped_session_is_a_404_and_is_not_created_on_the_way_past(): void
    {
        $client = self::createClient();
        $docker = $this->docker();
        $docker->resetCalls();

        $this->exec($client, 'refactor', ['cmd' => ['npm', 'test']]);

        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
        self::assertSame('no-such-session', $this->decode($client)['status']);
        self::assertFalse($docker->hasVolume('ww-ws-refactor'), 'a typo must never become a durable object');
        self::assertSame(['volumeExists'], $docker->calledMethods());
    }

    public function test_a_timeout_is_a_result_not_an_error(): void
    {
        $client = self::createClient();
        $this->docker()->linger();
        $this->docker()->createVolume('ww-ws-refactor', ['ww.session' => 'refactor']);

        $this->exec($client, 'refactor', ['cmd' => ['sleep', 'forever'], 'timeout' => 1]);

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode(), 'a killed command ran; that is a 200 with a flag');

        $body = $this->decode($client);
        self::assertTrue($body['timed_out']);
        self::assertSame(137, $body['exit_code']);
    }

    public function test_a_busy_broker_answers_429_and_says_it_does_not_queue(): void
    {
        $client = self::createClient();
        $this->docker()->createVolume('ww-ws-refactor', ['ww.session' => 'refactor']);

        // .env.test sets WW_MAX_CONCURRENCY=1, and the pool is process-wide by
        // design (§6b), so a slot held here is a slot the request cannot take.
        // Holding it directly is how a busy broker is represented in a
        // single-threaded test: two sequential requests cannot overlap, and a
        // command that has finished does not hold its slot — which is itself
        // the property that makes the pool honest.
        $slots = self::getContainer()->get(Slots::class);
        self::assertInstanceOf(Slots::class, $slots);
        $held = $slots->reserve();

        try {
            $this->exec($client, 'refactor', ['cmd' => ['sleep', '1']]);
        } finally {
            $held->release();
        }

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $client->getResponse()->getStatusCode());

        $body = $this->decode($client);
        self::assertSame('busy', $body['status']);
        self::assertStringContainsString('does not queue', (string) $body['reason']);
    }

    public function test_stdin_is_refused_with_501_and_a_way_forward(): void
    {
        $client = self::createClient();
        $this->docker()->createVolume('ww-ws-refactor', ['ww.session' => 'refactor']);

        $this->exec($client, 'refactor', ['cmd' => ['cat'], 'stdin' => "hello\n"]);

        self::assertSame(Response::HTTP_NOT_IMPLEMENTED, $client->getResponse()->getStatusCode());

        $body = $this->decode($client);
        self::assertSame('stdin-unsupported', $body['status']);
        self::assertStringContainsString('file in the workspace', (string) $body['reason']);
    }

    /**
     * The vocabulary's refusals come back as 422 with the reason intact —
     * a string command, a host network, an escaping workdir, a caller trying
     * to own PAGER.
     */
    public function test_a_request_that_cannot_be_expressed_is_422_with_the_reason(): void
    {
        $client = self::createClient();
        $this->docker()->createVolume('ww-ws-refactor', ['ww.session' => 'refactor']);

        $this->exec($client, 'refactor', ['cmd' => 'npm test']);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());

        $body = $this->decode($client);
        self::assertSame('invalid', $body['status']);
        self::assertStringContainsString('cmd must be a list of strings', (string) $body['reason']);

        $this->exec($client, 'refactor', ['cmd' => ['true'], 'network' => 'host']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());

        $this->exec($client, 'refactor', ['cmd' => ['true'], 'env' => ['PAGER' => 'less']]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('owned by the broker', (string) $this->decode($client)['reason']);
    }

    public function test_a_session_name_that_cannot_become_a_resource_is_422_before_docker_is_asked(): void
    {
        $client = self::createClient();
        $docker = $this->docker();
        $docker->resetCalls();

        $this->exec($client, 'Refactor!', ['cmd' => ['true']]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        self::assertSame([], $docker->calledMethods());
    }

    public function test_a_malformed_body_is_400(): void
    {
        $client = self::createClient();

        $client->request('POST', '/v1/sessions/refactor/exec', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
        ], '{');

        self::assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
        self::assertSame('bad-request', $this->decode($client)['status']);
    }

    public function test_a_daemon_that_cannot_be_reached_is_503_and_one_that_refuses_is_502(): void
    {
        $client = self::createClient();

        // Two requests, one scripted state: without this the kernel reboots
        // between them and the scripted double is a different instance by the
        // time the second request runs — which is itself worth knowing.
        $client->disableReboot();

        $this->docker()->createVolume('ww-ws-refactor', ['ww.session' => 'refactor']);

        $this->docker()->failNext('startContainer', DockerUnavailable::because('the proxy is not answering'));
        $this->exec($client, 'refactor', ['cmd' => ['true']]);
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $client->getResponse()->getStatusCode());

        $this->docker()->failNext('createContainer', DockerRefused::because('No such image: digitaladapt/work-warp:test-base'));
        $this->exec($client, 'refactor', ['cmd' => ['true']]);
        self::assertSame(Response::HTTP_BAD_GATEWAY, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('No such image', (string) $this->decode($client)['reason']);
    }

    public function test_output_that_is_not_utf8_is_scrubbed_rather_than_500ing(): void
    {
        // Measured while building 2a: JsonResponse *throws* on invalid UTF-8,
        // and a command's output is arbitrary bytes. U+FFFD in the position of
        // each byte that was not text.
        $client = self::createClient();
        $this->docker()->runToCompletion(0);
        $this->docker()->scriptNextLogs(new LogOutput("bad\xB1bytes\n", "\xFF\n"));
        $this->docker()->createVolume('ww-ws-refactor', ['ww.session' => 'refactor']);

        $this->exec($client, 'refactor', ['cmd' => ['cat', 'binary']]);

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $body = $this->decode($client);
        self::assertSame("bad\u{FFFD}bytes\n", $body['stdout']);
        self::assertSame("\u{FFFD}\n", $body['stderr']);
    }

    public function test_the_endpoint_sits_behind_the_same_gate_as_everything_else(): void
    {
        $client = self::createClient();

        $client->request('POST', '/v1/sessions/refactor/exec', [], [], ['CONTENT_TYPE' => 'application/json'], '{"cmd":["true"]}');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
    }

    /**
     * @param array<string, mixed>|string $body
     */
    private function exec(KernelBrowser $client, string $session, array|string $body): void
    {
        $client->request(
            'POST',
            '/v1/sessions/'.$session.'/exec',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
            ],
            \is_array($body) ? json_encode($body, \JSON_THROW_ON_ERROR) : $body,
        );
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
