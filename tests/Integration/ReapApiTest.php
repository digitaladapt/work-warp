<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Docker\DockerRefused;
use App\Docker\DockerUnavailable;
use App\Tests\Support\ScriptedDockerApi;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/reap`, through the real kernel: the gate, the route, the sweep,
 * the report — with Docker scripted, exactly as the rest of the suite runs it.
 *
 * The endpoint's contract is the report: `inspected` counts what the daemon
 * listed, `reaped` and `failed` say what happened to each container the sweep
 * acted on. A daemon-level failure is 503/502 in the established vocabulary;
 * a *container-level* failure is a `failed` entry in a 200, because a batch
 * operation that hides its successes behind a 5xx is less useful than one
 * that reports both.
 *
 * @internal
 */
final class ReapApiTest extends WebTestCase
{
    private const string TOKEN = 'test-token';

    public function test_a_sweep_reports_what_it_inspected_and_did(): void
    {
        $client = self::createClient();
        $docker = $this->docker();
        $docker->seedContainer('id-stopped', 'ww-demo-abc123', false, 1_767_225_200, ['ww.session' => 'demo', 'ww.ttl' => '300']);
        $docker->seedContainer('id-young', 'ww-demo-def456', true, 1_767_225_600, ['ww.session' => 'demo', 'ww.ttl' => '300']);

        $client->request('POST', '/v1/reap', [], [], $this->auth());

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        $body = $this->decode($client);

        self::assertSame(2, $body['inspected'], 'both containers were looked at');
        self::assertCount(1, $body['reaped'], 'one was past its TTL');
        self::assertSame('ww-demo-abc123', $body['reaped'][0]['container']);
        self::assertSame('removed', $body['reaped'][0]['action']);
        self::assertSame([], $body['failed']);
    }

    public function test_a_sweep_with_nothing_to_do_is_a_200_with_an_empty_report(): void
    {
        $client = self::createClient();
        $this->docker()->seedContainer('id-young', 'ww-demo-abc123', true, 1_767_225_600, ['ww.session' => 'demo', 'ww.ttl' => '300']);

        $client->request('POST', '/v1/reap', [], [], $this->auth());

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $body = $this->decode($client);
        self::assertSame(1, $body['inspected']);
        self::assertSame([], $body['reaped']);
        self::assertSame([], $body['failed']);
    }

    public function test_a_daemon_that_cannot_be_reached_is_503(): void
    {
        $client = self::createClient();
        $this->docker()->failNext('listContainers', DockerUnavailable::because('the proxy is not answering'));

        $client->request('POST', '/v1/reap', [], [], $this->auth());

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $client->getResponse()->getStatusCode());
        self::assertSame('unavailable', $this->decode($client)['status']);
    }

    public function test_a_daemon_that_refuses_is_502_and_keeps_the_daemons_words(): void
    {
        $client = self::createClient();
        $this->docker()->failNext('listContainers', DockerRefused::because('permission denied while trying to connect to the Docker daemon socket'));

        $client->request('POST', '/v1/reap', [], [], $this->auth());

        self::assertSame(Response::HTTP_BAD_GATEWAY, $client->getResponse()->getStatusCode());

        $body = $this->decode($client);
        self::assertSame('refused', $body['status']);
        self::assertStringContainsString('permission denied', (string) $body['reason']);
    }

    public function test_the_endpoint_is_behind_the_gate(): void
    {
        $client = self::createClient();

        $client->request('POST', '/v1/reap');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
        self::assertSame([], $this->docker()->calledMethods(), 'nothing may reach the sweep unauthenticated');
    }

    public function test_the_endpoint_is_post_only(): void
    {
        $client = self::createClient();

        $client->request('GET', '/v1/reap', [], [], $this->auth());

        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $client->getResponse()->getStatusCode());
    }

    /**
     * @return array<string, string>
     */
    private function auth(): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN];
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
