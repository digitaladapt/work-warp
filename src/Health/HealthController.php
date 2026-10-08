<?php

declare(strict_types=1);

namespace App\Health;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness and readiness, split as the standard requires (Guiding Light §8.4).
 *
 * `/health` touches nothing, so the most aggressive prober can hit it.
 *
 * `/ready` answers a question the broker can actually answer today: is it
 * configured to reach Docker at all? It deliberately does not dial the daemon —
 * a liveness probe must not fail because a dependency is briefly down, and
 * "cannot reach Docker right now" is a condition the *request* path should
 * report, not a reason to restart the broker. When the Docker client lands, the
 * reachability check belongs in a separate deep probe with its own route.
 */
final class HealthController
{
    public function __construct(
        private readonly string $dockerHost,
    ) {
    }

    #[Route('/health', name: 'health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok'], headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/ready', name: 'ready', methods: ['GET'])]
    public function ready(): JsonResponse
    {
        if ('' === trim($this->dockerHost)) {
            return new JsonResponse([
                'status' => 'unavailable',
                'reason' => 'WW_DOCKER_HOST is not set, so no command container could be created',
            ], Response::HTTP_SERVICE_UNAVAILABLE, ['Cache-Control' => 'no-store']);
        }

        return new JsonResponse([
            'status' => 'ready',
            'docker' => 'configured',
        ], headers: ['Cache-Control' => 'no-store']);
    }
}
