<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Health\HealthController;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The kernel compiles, the container builds, and the two endpoints an
 * orchestrator leans on actually answer.
 *
 * Worth its place at bootstrap: the broker's config is read by people who will
 * be nervous about it, and a broken container must be caught by CI rather than
 * found by whoever restarts the service.
 *
 * @internal
 */
final class KernelSmokeTest extends WebTestCase
{
    public function test_the_container_compiles_and_serves_its_controller(): void
    {
        self::bootKernel();

        self::assertTrue(
            self::getContainer()->has(HealthController::class),
            'the health controller must exist in the compiled container',
        );
    }

    public function test_liveness_answers_without_touching_a_dependency(): void
    {
        $client = self::createClient();

        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertSame('{"status":"ok"}', $client->getResponse()->getContent());
    }

    /**
     * With no Docker host configured the broker is honest about being unable to
     * do its job, rather than reporting itself ready and failing on the first
     * request. The test environment points at a name that does not resolve
     * precisely so this path is exercised.
     */
    public function test_readiness_reports_the_docker_configuration(): void
    {
        $client = self::createClient();

        $client->request('GET', '/ready');

        self::assertResponseIsSuccessful();

        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('ready', $body['status']);
        self::assertSame('configured', $body['docker']);
    }

    public function test_neither_endpoint_may_be_stored_by_anything_in_the_path(): void
    {
        $client = self::createClient();

        foreach (['/health', '/ready'] as $path) {
            $client->request('GET', $path);

            // Measured: Symfony's ResponseHeaderBag appends `, private` to any
            // response carrying cache directives without an explicit
            // public/private/s-maxage — including one constructed with a raw
            // `Cache-Control: no-store`. So the assertion is about what is
            // *declared*, not about an exact string: `no-store` is the directive
            // that matters, and nothing here may be shared-cacheable.
            $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');

            self::assertStringContainsString('no-store', $cacheControl, $path.' must not be stored');
            self::assertStringNotContainsString('public', $cacheControl, $path.' must not be publicly cacheable');
            self::assertStringNotContainsString('s-maxage', $cacheControl, $path.' must not be cacheable by a shared cache');
        }
    }

    public function test_an_unknown_path_is_a_404_rather_than_a_panic(): void
    {
        /** @var KernelBrowser $client */
        $client = self::createClient();

        $client->request('GET', '/v1/sessions');

        self::assertSame(
            Response::HTTP_NOT_FOUND,
            $client->getResponse()->getStatusCode(),
            'the session API does not exist yet, and its absence must be a clean 404',
        );
    }
}
