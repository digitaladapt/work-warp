<?php

declare(strict_types=1);

namespace App\Session;

use App\Docker\DockerApi;
use App\Docker\DockerRefused;
use App\Docker\DockerUnavailable;
use App\Docker\Labels;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The session API, beginning with the one endpoint where a session is born:
 * `POST /v1/sessions`.
 *
 * Today a session *is* its workspace — a named volume, created here and
 * labelled with the `ww.` vocabulary so everything that ever touches it can
 * tell whose it is (DOCKER-ACCESS.md §4, §6). The endpoint has **ensure
 * semantics**: creating a session that already exists adopts it (`200`) rather
 * than failing, because the caller's intent — "a session named X exists" — is
 * already satisfied, and a client retrying after a timeout should not have to
 * guess whether the first attempt landed. A fresh session answers `201`.
 *
 * The name is validated by `SessionName`, which is also the only source of the
 * derived resource names; the volume is named `ww-ws-<name>` and nothing here
 * ever spells that prefix itself.
 *
 * Failures keep their meanings from the client seam: a daemon that cannot be
 * reached is `503` (the request was fine; the world was not), a daemon that
 * refused is `502` carrying its own words, and a request that is malformed or
 * names something unpresentable is `400`/`422`.
 */
final class SessionController
{
    public function __construct(
        private readonly DockerApi $docker,
    ) {
    }

    #[Route('/v1/sessions', name: 'session_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $body = $request->getPayload()->all();
        } catch (JsonException) {
            return self::error(
                Response::HTTP_BAD_REQUEST,
                'bad-request',
                'the request body must be a JSON object, e.g. {"name":"refactor"}',
            );
        }

        $name = $body['name'] ?? null;

        // Unknown extra keys are ignored, deliberately: this object may grow
        // fields later, and a client that sends one early should not be told
        // its request is malformed. The fields that *are* read are validated
        // strictly.
        if (!\is_string($name)) {
            return self::error(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'invalid',
                'name is required and must be a string, e.g. {"name":"refactor"}',
            );
        }

        try {
            $session = SessionName::from($name);
        } catch (InvalidSessionName $e) {
            return self::error(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid', $e->getMessage());
        }

        $workspace = $session->workspaceVolume();

        try {
            if ($this->docker->volumeExists($workspace)) {
                return self::respond($session, Response::HTTP_OK);
            }

            $this->docker->createVolume($workspace, Labels::workspace($session));
        } catch (DockerUnavailable $e) {
            return self::error(Response::HTTP_SERVICE_UNAVAILABLE, 'unavailable', $e->getMessage());
        } catch (DockerRefused $e) {
            return self::error(Response::HTTP_BAD_GATEWAY, 'refused', $e->getMessage());
        }

        return self::respond($session, Response::HTTP_CREATED);
    }

    /**
     * The documented shape, and nothing else: `{session, workspace}`. Whether
     * this call created the volume or found it is carried by the status code,
     * where a client already knows to look.
     */
    private static function respond(SessionName $session, int $status): JsonResponse
    {
        return new JsonResponse([
            'session' => $session->value,
            'workspace' => $session->workspaceVolume(),
        ], $status, ['Cache-Control' => 'no-store']);
    }

    private static function error(int $status, string $slug, string $reason): JsonResponse
    {
        return new JsonResponse([
            'status' => $slug,
            'reason' => $reason,
        ], $status, ['Cache-Control' => 'no-store']);
    }
}
