<?php

declare(strict_types=1);

namespace App\Security;

use Override;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The bearer gate for `/v1`.
 *
 * Everything under `/v1` — every endpoint that can run a command or touch
 * Docker — requires the broker token, presented as `Authorization: Bearer
 * <token>`. `/health` and `/ready` stay open on purpose: they exist for an
 * orchestrator, touch nothing, and disclosing only "running" or "configured"
 * is not something to make a probe hold a secret for.
 *
 * It runs **before routing** (priority 100; Symfony's RouterListener is at 32),
 * so an unauthenticated caller gets the same `401` for every `/v1` path and
 * cannot use the status codes to enumerate which routes exist.
 *
 * An unset or empty token refuses with `503` rather than allowing: the whole
 * point of this component is that only the broker holds the Docker credential,
 * and that claim is false the moment the broker accepts anonymous callers
 * because a variable was left blank. Fail closed, and say why.
 *
 * This is a shared secret between the broker and its clients (`bin/ww-run`
 * today, the MCP surface later) — not a replacement for the network topology.
 * The session is not on the proxy's network, and only the broker authenticates
 * to Docker; this gate is what makes a network mistake survivable
 * (DOCKER-ACCESS.md §4, SECURITY.md).
 */
final class BrokerTokenListener implements EventSubscriberInterface
{
    /**
     * Methods that will never carry a credential in a useful way are still
     * gated: this is not an auth framework, it is one gate, and letting a verb
     * through unauthenticated to save a line would be the bug.
     */
    private const string GATED_PREFIX = '/v1';

    public function __construct(
        private readonly string $configuredToken,
    ) {
    }

    #[Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 100]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // Sub-requests (fragments, forwards) are already inside the process;
        // only the incoming request carries a client's credential.
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        // Exactly `/v1` or something under `/v1/`: a bare `str_starts_with`
        // would also catch a future `/v10` and quietly gate a surface that has
        // nothing to do with this one.
        if (self::GATED_PREFIX !== $path && !str_starts_with($path, self::GATED_PREFIX.'/')) {
            return;
        }

        $configured = trim($this->configuredToken);

        if ('' === $configured) {
            $event->setResponse(self::refusal(
                Response::HTTP_SERVICE_UNAVAILABLE,
                'unavailable',
                'WW_TOKEN is not set, so the broker cannot authenticate callers; set it before serving /v1 requests',
            ));

            return;
        }

        $presented = self::bearer($request);

        if (null === $presented || !hash_equals($configured, $presented)) {
            $event->setResponse(self::refusal(
                Response::HTTP_UNAUTHORIZED,
                'unauthorized',
                'a bearer token is required for /v1 requests',
            ));
        }
    }

    /**
     * The token from `Authorization: Bearer <token>`, or null.
     *
     * `hash_equals` for the comparison, both because it is the constant-time
     * one and because it is the one whose name says what is being done.
     */
    private static function bearer(Request $request): ?string
    {
        $header = $request->headers->get('Authorization');

        if (null === $header || 1 !== preg_match('/^Bearer\s+(\S+)\z/i', trim($header), $matches)) {
            return null;
        }

        return $matches[1];
    }

    private static function refusal(int $status, string $slug, string $reason): JsonResponse
    {
        $headers = ['Cache-Control' => 'no-store'];

        if (Response::HTTP_UNAUTHORIZED === $status) {
            $headers['WWW-Authenticate'] = 'Bearer';
        }

        return new JsonResponse([
            'status' => $slug,
            'reason' => $reason,
        ], $status, $headers);
    }
}
