<?php

declare(strict_types=1);

namespace App\Build;

use App\Docker\DockerRefused;
use App\Docker\DockerUnavailable;
use App\Exec\SlotUnavailable;
use App\Session\InvalidSessionName;
use App\Session\NoSuchSession;
use App\Session\SessionName;
use App\Support\Utf8;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /v1/sessions/{name}/build` — the scratch build path (DOCKER-ACCESS.md
 * §10 step 4a).
 *
 * The request is shaped differently from `exec`, and the difference is forced
 * by what a build *is*: the body is the build context (a tar), so the
 * parameters ride in the query — `?tag=intl&network=session&timeout=1800` —
 * exactly as the daemon itself takes them (`POST /build?t=…` with the tar as
 * the body). The broker's edge and its upstream therefore agree about where
 * each thing lives.
 *
 * The body is deliberately never parsed: `getPayload()` would try to decode a
 * tar as JSON. It is read as a stream and handed to the runner, where the cap
 * is enforced while it streams.
 *
 * Every refusal is a status code with a reason, the same mapping as exec:
 *
 * - `404` — no such session.
 * - `422` — the name or the build parameters cannot be expressed.
 * - `413` — the context exceeds the cap. Checked twice: on the declared
 *   `Content-Length` when there is one (so an oversized upload is refused
 *   before it starts), and by counting bytes as they stream when there is not.
 * - `429` — every slot is taken; the broker does not queue.
 * - `503` / `502` — the daemon could not be reached / refused.
 *
 * Failures come in two shapes, and they are told apart because the daemon
 * tells them apart (both measured against Docker 29.8):
 *
 * - **A build that ran and failed mid-stream** — the common case, a `RUN`
 *   that exits non-zero — arrives as a `200` stream carrying `{"error":…}`,
 *   and is reported as `200` with `image: null` and the diagnosis in `output`.
 *   Not a 5xx, for the same reason a killed command is not: the difference
 *   between "the build failed" and "the broker broke" is precisely the
 *   difference the caller needs.
 * - **A build the daemon refused before streaming** — a malformed query, or an
 *   environment where it cannot start one at all (measured here: `unshare:
 *   operation not permitted` in a sandbox without namespace rights) — is an
 *   HTTP-level error from the daemon and leaves as `DockerRefused`, the
 *   broker's `502`, with the daemon's own words. It did not run, and saying it
 *   "failed" would imply it did.
 */
final class BuildController
{
    public function __construct(
        private readonly BuildRunner $runner,
    ) {
    }

    #[Route('/v1/sessions/{name}/build', name: 'session_build', methods: ['POST'])]
    public function build(string $name, Request $request): JsonResponse
    {
        try {
            $session = SessionName::from($name);
        } catch (InvalidSessionName $e) {
            return self::error(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid', $e->getMessage());
        }

        try {
            $build = BuildRequest::fromQuery($request->query->all());
        } catch (InvalidBuildRequest $e) {
            return self::error(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid', $e->getMessage());
        }

        // The declared length, when there is one, is a courtesy check: it lets
        // an oversized context be refused before a byte is uploaded. The
        // authoritative check counts real bytes (ContextStream) — a chunked
        // request declares nothing, and a declared length is a claim, not a
        // measurement.
        $declaredLength = $request->headers->get('Content-Length');

        if (null !== $declaredLength && ctype_digit($declaredLength) && (int) $declaredLength > BuildRequest::MAX_CONTEXT_BYTES) {
            return self::error(
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
                'too-large',
                BuildContextTooLarge::message(BuildRequest::MAX_CONTEXT_BYTES),
            );
        }

        $context = $request->getContent(asResource: true);

        try {
            $result = $this->runner->run($session, $build, $context);
        } catch (NoSuchSession $e) {
            return self::error(Response::HTTP_NOT_FOUND, 'no-such-session', $e->getMessage());
        } catch (SlotUnavailable $e) {
            return self::error(Response::HTTP_TOO_MANY_REQUESTS, 'busy', $e->getMessage());
        } catch (BuildContextTooLarge $e) {
            return self::error(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'too-large', $e->getMessage());
        } catch (DockerUnavailable $e) {
            return self::error(Response::HTTP_SERVICE_UNAVAILABLE, 'unavailable', $e->getMessage());
        } catch (DockerRefused $e) {
            return self::error(Response::HTTP_BAD_GATEWAY, 'refused', $e->getMessage());
        }

        return new JsonResponse([
            'image' => $result->image,
            'output' => Utf8::scrub($result->output),
            'truncated' => $result->truncated,
            'timed_out' => $result->timedOut,
        ], Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    private static function error(int $status, string $slug, string $reason): JsonResponse
    {
        return new JsonResponse([
            'status' => $slug,
            'reason' => $reason,
        ], $status, ['Cache-Control' => 'no-store']);
    }
}
