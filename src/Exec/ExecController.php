<?php

declare(strict_types=1);

namespace App\Exec;

use App\Docker\DockerRefused;
use App\Docker\DockerUnavailable;
use App\Session\InvalidSessionName;
use App\Session\NoSuchSession;
use App\Session\SessionName;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /v1/sessions/{name}/exec` — the 95% win (DOCKER-ACCESS.md §10 step 2).
 *
 * A thin HTTP edge over `ExecRunner`: parse, delegate, map. Every refusal here
 * is a status code with a reason, and the mapping is the design's own:
 *
 * - `404` — no such session; a command aimed at a mistyped name must not
 *   create the session on the way past.
 * - `422` — the name or the exec request cannot be expressed (validated by
 *   `SessionName` and `ExecRequest`, whose construction is authoritative).
 * - `429` — every slot is taken. The broker does not queue, so this is a
 *   "come back" rather than a wait (§6b).
 * - `501` — `stdin` was asked for and cannot be delivered yet.
 * - `503` / `502` — the daemon could not be reached / refused, exactly as
 *   the session endpoint reports them.
 *
 * A command that is killed at its deadline is **not** an error status: it ran,
 * it was stopped, and the result says so (`timed_out: true`, exit code 137).
 * Rewriting that into a 5xx would lose the difference between "the broker
 * broke" and "the command was too slow" — which is precisely the difference
 * the caller needs.
 *
 * Output is scrubbed to valid UTF-8 before it is encoded: `JsonResponse`
 * *throws* on invalid UTF-8 rather than substituting, and a command's output
 * is arbitrary bytes. Measured, not assumed — this was found while building
 * the 2a client and is the reason this method exists rather than being
 * implied.
 */
final class ExecController
{
    public function __construct(
        private readonly ExecRunner $runner,
    ) {
    }

    #[Route('/v1/sessions/{name}/exec', name: 'session_exec', methods: ['POST'])]
    public function exec(string $name, Request $request): JsonResponse
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
                'the request body must be a JSON object, e.g. {"cmd":["npm","test"]}',
            );
        }

        try {
            $exec = ExecRequest::fromArray($payload);
        } catch (InvalidExecRequest $e) {
            return self::error(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid', $e->getMessage());
        }

        try {
            $result = $this->runner->run($session, $exec);
        } catch (NoSuchSession $e) {
            return self::error(Response::HTTP_NOT_FOUND, 'no-such-session', $e->getMessage());
        } catch (StdinUnsupported $e) {
            return self::error(Response::HTTP_NOT_IMPLEMENTED, 'stdin-unsupported', $e->getMessage());
        } catch (SlotUnavailable $e) {
            return self::error(Response::HTTP_TOO_MANY_REQUESTS, 'busy', $e->getMessage());
        } catch (DockerUnavailable $e) {
            return self::error(Response::HTTP_SERVICE_UNAVAILABLE, 'unavailable', $e->getMessage());
        } catch (DockerRefused $e) {
            return self::error(Response::HTTP_BAD_GATEWAY, 'refused', $e->getMessage());
        }

        return new JsonResponse([
            'exit_code' => $result->exitCode,
            'stdout' => self::utf8($result->stdout),
            'stderr' => self::utf8($result->stderr),
            'truncated' => $result->truncated,
            'timed_out' => $result->timedOut,
        ], Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    /**
     * Valid UTF-8 passes through untouched; anything else gets U+FFFD in the
     * position of each invalid byte, via the one PHP tool that does this
     * without silent loss: `json_encode` with `JSON_INVALID_UTF8_SUBSTITUTE`,
     * decoded straight back. Checked against the alternative
     * (`mb_convert_encoding`) while building this, because mbstring replaces
     * with `?` by default — losing the distinction between "a byte that was
     * not text" and "a question mark the command actually printed".
     */
    private static function utf8(string $text): string
    {
        if (1 === preg_match('//u', $text)) {
            return $text;
        }

        $scrubbed = json_decode(
            json_encode($text, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        return \is_string($scrubbed) ? $scrubbed : '';
    }

    private static function error(int $status, string $slug, string $reason): JsonResponse
    {
        return new JsonResponse([
            'status' => $slug,
            'reason' => $reason,
        ], $status, ['Cache-Control' => 'no-store']);
    }
}
