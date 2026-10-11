<?php

declare(strict_types=1);

namespace App\Janitor;

use App\Docker\DockerRefused;
use App\Docker\DockerUnavailable;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /v1/reap` — the sweep on demand (DOCKER-ACCESS.md §5: "janitor: stop
 * past-TTL, report").
 *
 * The same `Janitor` the scheduled worker uses; this endpoint exists so a
 * caller with the token can ask "clean up now" and *see the answer*, which is
 * what `ww-run reap` becomes on the far side of §9. A sweep is idempotent in
 * the way that matters — running it twice removes what the first pass
 * stopped — so an endpoint that can always be asked is safe.
 *
 * Failure shapes follow the vocabulary the other endpoints established: the
 * daemon unreachable is `503`, the daemon refusing is `502`. A container the
 * sweep could not act on is *not* an error status: it is a `failed` entry in
 * a `200` report, because a batch operation that tells you what it managed
 * and what it could not is more useful than a 5xx that hides the successes.
 */
final class ReapController
{
    public function __construct(
        private readonly Janitor $janitor,
    ) {
    }

    #[Route('/v1/reap', name: 'reap', methods: ['POST'])]
    public function reap(): JsonResponse
    {
        try {
            $report = $this->janitor->sweep();
        } catch (DockerUnavailable $e) {
            return self::error(Response::HTTP_SERVICE_UNAVAILABLE, 'unavailable', $e->getMessage());
        } catch (DockerRefused $e) {
            return self::error(Response::HTTP_BAD_GATEWAY, 'refused', $e->getMessage());
        }

        return new JsonResponse($report->toArray(), Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    private static function error(int $status, string $slug, string $reason): JsonResponse
    {
        return new JsonResponse([
            'status' => $slug,
            'reason' => $reason,
        ], $status, ['Cache-Control' => 'no-store']);
    }
}
