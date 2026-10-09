<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\ScriptedDockerApi;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The bearer gate on `/v1`: one answer for every unauthenticated caller, and
 * nothing about the routing table leaked through status codes.
 *
 * @internal
 */
final class BrokerTokenGateTest extends WebTestCase
{
    public function test_health_and_readiness_stay_open(): void
    {
        $client = self::createClient();

        foreach (['/health', '/ready'] as $path) {
            $client->request('GET', $path);

            self::assertSame(
                Response::HTTP_OK,
                $client->getResponse()->getStatusCode(),
                $path.' exists for an orchestrator and must not require a secret',
            );
        }
    }

    public function test_a_missing_token_answers_401_and_touches_nothing(): void
    {
        $client = self::createClient();
        $docker = self::getContainer()->get(ScriptedDockerApi::class);
        self::assertInstanceOf(ScriptedDockerApi::class, $docker);

        $client->request('POST', '/v1/sessions', [], [], ['CONTENT_TYPE' => 'application/json'], '{"name":"refactor"}');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
        self::assertSame('Bearer', $client->getResponse()->headers->get('WWW-Authenticate'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('unauthorized', $body['status']);
        self::assertSame([], $docker->calledMethods(), 'an unauthenticated request must not reach the controller');
    }

    public function test_a_wrong_token_is_refused_like_a_missing_one(): void
    {
        $client = self::createClient();

        $client->request('POST', '/v1/sessions', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer not-the-token',
        ], '{"name":"refactor"}');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
    }

    #[DataProvider('malformedCredentials')]
    public function test_a_credential_that_is_not_a_bearer_header_is_refused(string $header): void
    {
        $client = self::createClient();

        $client->request('POST', '/v1/sessions', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => $header,
        ], '{"name":"refactor"}');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedCredentials(): iterable
    {
        yield 'empty' => [''];
        yield 'scheme only' => ['Bearer'];
        yield 'wrong scheme' => ['Basic dGVzdC10b2tlbg=='];
        yield 'two credentials' => ['Bearer test-token extra'];
    }

    /**
     * The same unknown path answers 401 without a token and 404 with one:
     * the gate runs before routing, so which routes exist cannot be probed
     * with an unauthenticated request.
     */
    public function test_the_same_path_is_401_without_a_token_and_404_with_one(): void
    {
        $client = self::createClient();

        $client->request('GET', '/v1/does-not-exist');
        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());

        $this->authorized($client, 'GET', '/v1/does-not-exist');
        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
    }

    public function test_the_bearer_scheme_is_case_insensitive(): void
    {
        $client = self::createClient();

        $this->authorized($client, 'GET', '/v1/does-not-exist', 'bearer test-token');

        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
    }

    private function authorized(KernelBrowser $client, string $method, string $path, string $header = 'Bearer test-token'): void
    {
        $client->request($method, $path, [], [], ['HTTP_AUTHORIZATION' => $header]);
    }
}
