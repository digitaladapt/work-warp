<?php

declare(strict_types=1);

namespace App\Stack;

use App\Docker\DockerRefused;
use App\Docker\DockerUnavailable;
use App\Exec\SlotUnavailable;
use App\Session\InvalidSessionName;
use App\Session\NoSuchSession;
use App\Session\SessionName;
use App\Support\Utf8;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /v1/sessions/{name}/stack` — the compose harness (DOCKER-ACCESS.md §10
 * step 4b).
 *
 * One endpoint, three actions in the body (`up`/`down`/`logs`), because the
 * three are one resource's lifecycle and the request object should be one
 * typed shape rather than three paths that share their parsing. The body is
 * JSON: `{"action": "up", "services": {...}}` or `{"action": "down"}`.
 *
 * The endpoint is the *compose altitude* of this API's argument. Where `exec`
 * makes `--privileged` unexpressible in a container create, this makes
 * `privileged: true`, `network_mode: host` and `volumes: [/:/host]`
 * unexpressible in a stack: there is no field for them in `StackService`, and
 * the broker renders the stack itself rather than handing a caller's compose
 * file to a compose implementation, so there is no intermediate format in
 * which those words could exist. What the session *cannot* do — run
 * `docker compose` directly — is a fact about the topology (no daemon route);
 * what it *can* do is this endpoint, and this endpoint is deliberately the
 * broker's vocabulary rather than Compose's.
 *
 * Status mapping, the same family as everywhere else:
 *
 * - `404` — no such session.
 * - `422` — the name, the action or the stack cannot be expressed (unknown
 *   dependency, cycle, `healthy` on a service with no health command, an
 *   image reference or mount that is out of grammar).
 * - `429` — `up` could not take a slot; `down` and `logs` never take one and
 *   so never answer this.
 * - `503` / `502` — the daemon could not be reached / refused.
 *
 * A bring-up that *ran and failed* — a dependency exited non-zero, a service
 * never became healthy, an image could not be pulled — is `200` with the
 * partial outcome and the failure named, for the same reason a failed build
 * is: the difference between "the stack did not come up" and "the broker
 * broke" is exactly the difference the caller needs.
 */
final class StackController
{
    public function __construct(
        private readonly StackRunner $runner,
    ) {
    }

    #[Route('/v1/sessions/{name}/stack', name: 'session_stack', methods: ['POST'])]
    public function stack(string $name, Request $request): JsonResponse
    {
        try {
            $session = SessionName::from($name);
        } catch (InvalidSessionName $e) {
            return self::error(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid', $e->getMessage());
        }

        try {
            $payload = $request->getPayload()->all();
        } catch (JsonException) {
            return self::error(
                Response::HTTP_BAD_REQUEST,
                'bad-request',
                'the request body must be a JSON object, e.g. {"action":"up","services":{"web":{"image":"nginx:alpine"}}}',
            );
        }

        try {
            $stack = StackRequest::fromArray($payload);
        } catch (InvalidStackRequest $e) {
            return self::error(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid', $e->getMessage());
        }

        try {
            return match ($stack->action) {
                StackAction::Up => $this->up($session, $stack),
                StackAction::Down => $this->down($session, $stack),
                StackAction::Logs => $this->logs($session),
            };
        } catch (NoSuchSession $e) {
            return self::error(Response::HTTP_NOT_FOUND, 'no-such-session', $e->getMessage());
        } catch (InvalidStackRequest $e) {
            // The planner resolves *inside* the runner — deliberately, so
            // every ordering refusal happens before anything is created — so
            // its 422 arrives here rather than at request construction.
            return self::error(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid', $e->getMessage());
        } catch (SlotUnavailable $e) {
            return self::error(Response::HTTP_TOO_MANY_REQUESTS, 'busy', $e->getMessage());
        } catch (DockerUnavailable $e) {
            return self::error(Response::HTTP_SERVICE_UNAVAILABLE, 'unavailable', $e->getMessage());
        } catch (DockerRefused $e) {
            return self::error(Response::HTTP_BAD_GATEWAY, 'refused', $e->getMessage());
        }
    }

    private function up(SessionName $session, StackRequest $request): JsonResponse
    {
        $result = $this->runner->up($session, $request);

        $services = [];

        foreach ($result->services as $state) {
            $services[] = [
                'name' => $state->name,
                'container' => $state->container,
                'image' => $state->image,
                'running' => $state->running,
            ];
        }

        return new JsonResponse([
            'services' => $services,
            'failure' => null === $result->failure ? null : [
                'service' => $result->failure['service'],
                'reason' => Utf8::scrub($result->failure['reason']),
            ],
            'timed_out' => $result->timedOut,
        ], Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    private function down(SessionName $session, StackRequest $request): JsonResponse
    {
        $result = $this->runner->down($session, $request);

        $failures = [];

        foreach ($result->failures as $failure) {
            $failures[] = ['name' => $failure['name'], 'reason' => Utf8::scrub($failure['reason'])];
        }

        return new JsonResponse([
            'containers_removed' => $result->containers,
            'volumes_removed' => $result->volumes,
            'failures' => $failures,
        ], Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    private function logs(SessionName $session): JsonResponse
    {
        $result = $this->runner->logs($session, 1048576);

        return new JsonResponse([
            'output' => Utf8::scrub($result->output),
            'truncated' => $result->truncated,
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
