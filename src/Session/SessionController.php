<?php

declare(strict_types=1);

namespace App\Session;

use App\Docker\DockerApi;
use App\Docker\DockerRefused;
use App\Docker\DockerUnavailable;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The session API: where a session is born, described, listed, and destroyed.
 *
 * Today a session *is* its workspace — a named volume, created here and
 * labelled with the `ww.` vocabulary so everything that ever touches it can
 * tell whose it is (DOCKER-ACCESS.md §4, §6). These endpoints are the
 * complete surface §5 names for it:
 *
 * - `POST /v1/sessions` — born. Ensure semantics: a session that already
 *   exists is adopted (`200`) rather than failing, because the caller's
 *   intent — "a session named X exists" — is already satisfied, and a client
 *   retrying after a timeout should not have to guess whether the first
 *   attempt landed. A fresh session answers `201`.
 * - `GET /v1/sessions` — every session, discovered from the volumes that
 *   carry the workspace label. The label is the authority, never a name.
 * - `GET /v1/sessions/{name}` — one session: workspace, containers, ledger.
 * - `GET /v1/sessions/{name}/ps` — the containers alone, which is all
 *   `ww-run ps` ever printed.
 * - `DELETE /v1/sessions/{name}` — destruction, in the order and under the
 *   one rule `SessionDestroyer` states. The body is optional;
 *   `{"keep_workspace": true}` keeps every volume.
 *
 * The name is validated by `SessionName`, which is also the only source of
 * the derived resource names; nothing here spells a `ww-` prefix itself, and
 * membership is decided by labels, never by names.
 *
 * Failures keep their meanings from the client seam, uniformly: unreachable
 * daemon `503`, refusing daemon `502` with its own words, malformed or
 * unpresentable request `400`/`422`, missing session `404`. `DELETE` of a
 * missing session is a `404` too, deliberately — "ensure" is a POST idea,
 * and a deletion that reports success for a name that never existed teaches
 * the caller the wrong thing about its own state.
 */
final class SessionController
{
    public function __construct(
        private readonly DockerApi $docker,
        private readonly SessionWorkspaces $workspaces,
        private readonly SessionContainers $containers,
        private readonly SessionLedger $ledger,
        private readonly SessionDestroyer $destroyer,
    ) {
    }

    #[Route('/v1/sessions', name: 'session_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $body = $request->getPayload()->all();
        } catch (JsonException) {
            return self::badRequest('the request body must be a JSON object, e.g. {"name":"refactor"}');
        }

        $name = $body['name'] ?? null;

        // Unknown extra keys are ignored, deliberately: this object may grow
        // fields later, and a client that sends one early should not be told
        // its request is malformed. The fields that *are* read are validated
        // strictly.
        if (!\is_string($name)) {
            return self::invalid('name is required and must be a string, e.g. {"name":"refactor"}');
        }

        $session = $this->sessionNamed($name);

        if ($session instanceof JsonResponse) {
            return $session;
        }

        try {
            // `ensure` owns the definition of a session (a labelled workspace
            // volume) for every surface that needs it; the boolean is the
            // whole of the ensure semantics, and the status is all a client
            // needs from it.
            $created = $this->workspaces->ensure($session);
        } catch (DockerUnavailable|DockerRefused $e) {
            return self::dockerError($e);
        }

        return self::respond($session, $created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    #[Route('/v1/sessions', name: 'session_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        try {
            $sessions = $this->workspaces->all();
        } catch (DockerUnavailable|DockerRefused $e) {
            return self::dockerError($e);
        }

        return new JsonResponse($sessions, Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    #[Route('/v1/sessions/{name}', name: 'session_show', methods: ['GET'])]
    public function show(string $name): JsonResponse
    {
        try {
            $session = SessionName::from($name);
        } catch (InvalidSessionName $e) {
            return self::invalid($e->getMessage());
        }

        try {
            if (!$this->docker->volumeExists($session->workspaceVolume())) {
                return self::noSuchSession($session);
            }

            $containers = $this->containers->forSession($session);
        } catch (DockerUnavailable|DockerRefused $e) {
            return self::dockerError($e);
        }

        // The ledger is read *after* the docker answer, and its own failures
        // are already the best-effort `null`: a session view that is true
        // must not become a 5xx because a convenience read could not happen.
        $ledger = $this->ledger->read($session);

        return new JsonResponse([
            'session' => $session->value,
            'workspace' => $session->workspaceVolume(),
            'containers' => array_map(static fn (SessionContainer $c): array => $c->toArray(), $containers),
            'ledger' => null === $ledger ? null : [
                'days' => $ledger['days'],
                'today' => $ledger['today'],
            ],
        ], Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    #[Route('/v1/sessions/{name}/ps', name: 'session_ps', methods: ['GET'])]
    public function ps(string $name): JsonResponse
    {
        try {
            $session = SessionName::from($name);
        } catch (InvalidSessionName $e) {
            return self::invalid($e->getMessage());
        }

        try {
            if (!$this->docker->volumeExists($session->workspaceVolume())) {
                return self::noSuchSession($session);
            }

            $containers = $this->containers->forSession($session);
        } catch (DockerUnavailable|DockerRefused $e) {
            return self::dockerError($e);
        }

        return new JsonResponse(
            array_map(static fn (SessionContainer $c): array => $c->toArray(), $containers),
            Response::HTTP_OK,
            ['Cache-Control' => 'no-store'],
        );
    }

    #[Route('/v1/sessions/{name}', name: 'session_destroy', methods: ['DELETE'])]
    public function destroy(string $name, Request $request): JsonResponse
    {
        try {
            $session = SessionName::from($name);
        } catch (InvalidSessionName $e) {
            return self::invalid($e->getMessage());
        }

        try {
            $body = $request->getPayload()->all();
        } catch (JsonException) {
            return self::badRequest('the request body must be a JSON object or empty, e.g. {"keep_workspace":true}');
        }

        $keepWorkspace = $body['keep_workspace'] ?? false;

        if (!\is_bool($keepWorkspace)) {
            return self::invalid('keep_workspace must be a boolean when present');
        }

        try {
            $deletion = $this->destroyer->destroy($session, $keepWorkspace);
        } catch (NoSuchSession $e) {
            return self::error(Response::HTTP_NOT_FOUND, 'no-such-session', $e->getMessage());
        } catch (DockerUnavailable|DockerRefused $e) {
            return self::dockerError($e);
        }

        return new JsonResponse($deletion->toArray(), Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    /**
     * Validate a path name, or an error response saying why not.
     */
    private function sessionNamed(string $name): SessionName|JsonResponse
    {
        try {
            return SessionName::from($name);
        } catch (InvalidSessionName $e) {
            return self::invalid($e->getMessage());
        }
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

    private static function noSuchSession(SessionName $session): JsonResponse
    {
        return self::error(Response::HTTP_NOT_FOUND, 'no-such-session', NoSuchSession::named($session->value)->getMessage());
    }

    private static function dockerError(DockerUnavailable|DockerRefused $e): JsonResponse
    {
        return $e instanceof DockerUnavailable
            ? self::error(Response::HTTP_SERVICE_UNAVAILABLE, 'unavailable', $e->getMessage())
            : self::error(Response::HTTP_BAD_GATEWAY, 'refused', $e->getMessage());
    }

    private static function badRequest(string $reason): JsonResponse
    {
        return self::error(Response::HTTP_BAD_REQUEST, 'bad-request', $reason);
    }

    private static function invalid(string $reason): JsonResponse
    {
        return self::error(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid', $reason);
    }

    private static function error(int $status, string $slug, string $reason): JsonResponse
    {
        return new JsonResponse([
            'status' => $slug,
            'reason' => $reason,
        ], $status, ['Cache-Control' => 'no-store']);
    }
}
