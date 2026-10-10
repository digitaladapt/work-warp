<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Docker\DockerRefused;
use App\Docker\DockerUnavailable;
use App\Tests\Support\ScriptedDockerApi;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/sessions` — the first endpoint where a session exists.
 *
 * The route runs through the real kernel and the real gate; only Docker is
 * scripted, via the test-environment alias, because CI has no daemon and these
 * assertions are about what the broker *asked for*. The ensure-semantics
 * (201 fresh, 200 adopted) are asserted through the scripted daemon's call
 * log, not inferred from the status code.
 *
 * @internal
 */
final class SessionApiTest extends WebTestCase
{
    private const string TOKEN = 'test-token';

    public function test_creating_a_fresh_session_creates_and_labels_its_workspace(): void
    {
        $client = self::createClient();
        $docker = $this->docker();
        $docker->resetCalls();

        $this->post($client, '/v1/sessions', ['name' => 'refactor']);

        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode());
        self::assertSame(
            ['session' => 'refactor', 'workspace' => 'ww-ws-refactor'],
            $this->decode($client),
        );

        self::assertTrue($docker->hasVolume('ww-ws-refactor'));
        self::assertSame(
            ['ww.created-by' => 'work-warp', 'ww.session' => 'refactor', 'ww.kind' => 'workspace'],
            $docker->labelsOfVolume('ww-ws-refactor'),
            'ownership is decided by labels, so the volume must carry the vocabulary',
        );
        self::assertSame(['volumeExists', 'createVolume'], $docker->calledMethods());
    }

    public function test_a_session_response_is_never_stored(): void
    {
        $client = self::createClient();

        $this->post($client, '/v1/sessions', ['name' => 'refactor']);

        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function test_creating_a_session_that_exists_adopts_it_without_touching_docker_again(): void
    {
        $client = self::createClient();
        $docker = $this->docker();
        $docker->createVolume('ww-ws-refactor', ['ww.session' => 'refactor']);
        $docker->resetCalls();

        $this->post($client, '/v1/sessions', ['name' => 'refactor']);

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertSame(
            ['volumeExists'],
            $docker->calledMethods(),
            'the second create adopts what exists; it must not create a second volume or relabel one',
        );
    }

    public function test_unknown_extra_fields_are_ignored_rather_than_refused(): void
    {
        $client = self::createClient();

        $this->post($client, '/v1/sessions', ['name' => 'refactor', 'note' => 'hello']);

        self::assertSame(Response::HTTP_CREATED, $client->getResponse()->getStatusCode());
    }

    /**
     * Names that cannot become a `ww-` resource without smuggling something
     * through: uppercase, a space, a path escape, a newline, and one character
     * too many. The newline case is not hypothetical — the first version of the
     * pattern accepted it (CHANGELOG, 2026-10-08).
     */
    #[DataProvider('unpresentableNames')]
    public function test_a_name_that_cannot_become_a_resource_is_refused(string $name): void
    {
        $client = self::createClient();
        $docker = $this->docker();
        $docker->resetCalls();

        $this->post($client, '/v1/sessions', ['name' => $name]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        self::assertSame('invalid', $this->decode($client)['status']);
        self::assertSame([], $docker->calledMethods(), 'an invalid name must be refused before Docker is asked anything');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unpresentableNames(): iterable
    {
        yield 'uppercase' => ['Refactor'];
        yield 'space' => ['refactor this'];
        yield 'path escape' => ['../refactor'];
        yield 'slash' => ['refactor/x'];
        yield 'newline' => ["refactor\n"];
        yield 'leading dash' => ['-refactor'];
        yield 'too long' => [str_repeat('a', 32)];
        yield 'empty' => [''];
    }

    /**
     * @param array<string, mixed>|string $body
     */
    #[DataProvider('malformedRequests')]
    public function test_a_malformed_request_is_refused_with_a_reason(array|string $body, int $expectedStatus): void
    {
        $client = self::createClient();

        $this->post($client, '/v1/sessions', $body);

        self::assertSame($expectedStatus, $client->getResponse()->getStatusCode());

        $decoded = $this->decode($client);
        self::assertIsString($decoded['reason'] ?? null, 'a refusal must say why');
    }

    /**
     * @return iterable<string, array{array<string, mixed>|string, int}>
     */
    public static function malformedRequests(): iterable
    {
        yield 'missing name' => [['note' => 'hello'], Response::HTTP_UNPROCESSABLE_ENTITY];
        yield 'numeric name' => [['name' => 42], Response::HTTP_UNPROCESSABLE_ENTITY];
        yield 'list name' => [['name' => ['refactor']], Response::HTTP_UNPROCESSABLE_ENTITY];
        yield 'json that does not parse' => ['{', Response::HTTP_BAD_REQUEST];
        yield 'json that is not an object' => ['"refactor"', Response::HTTP_BAD_REQUEST];
    }

    public function test_a_daemon_that_cannot_be_reached_is_503_and_says_so(): void
    {
        $client = self::createClient();
        $this->docker()->failNext('volumeExists', DockerUnavailable::because('the proxy is not answering'));

        $this->post($client, '/v1/sessions', ['name' => 'refactor']);

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $client->getResponse()->getStatusCode());
        self::assertSame('unavailable', $this->decode($client)['status']);
    }

    public function test_a_daemon_that_refuses_is_502_and_keeps_the_daemons_words(): void
    {
        $client = self::createClient();
        $this->docker()->failNext('createVolume', DockerRefused::because('No such image: digitaladapt/work-warp:test-base'));

        $this->post($client, '/v1/sessions', ['name' => 'refactor']);

        self::assertSame(Response::HTTP_BAD_GATEWAY, $client->getResponse()->getStatusCode());

        $body = $this->decode($client);
        self::assertSame('refused', $body['status']);
        self::assertStringContainsString('No such image', (string) ($body['reason'] ?? ''));
    }

    /**
     * @param array<string, mixed>|string $body
     */
    private function post(KernelBrowser $client, string $path, array|string $body, ?string $token = self::TOKEN): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        $client->request('POST', $path, [], [], $server, \is_array($body) ? json_encode($body, \JSON_THROW_ON_ERROR) : $body);
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
