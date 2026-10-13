<?php

declare(strict_types=1);

namespace App\Mcp;

use GuzzleHttp\Psr7\HttpFactory;
use Mcp\Server;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\ServerRequestInterface as PsrRequestInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /mcp` — MCP over HTTP (the streamable transport).
 *
 * The work is split along the line the SDK draws: this controller does HTTP
 * (Symfony request → PSR-7 → back) and the SDK does protocol (parsing,
 * dispatch, validation, session bookkeeping). The controller therefore holds no
 * JSON-RPC knowledge at all — no id handling, no error-code table, no
 * media-type negotiation.
 *
 * **It is mounted under `/v1`, and that is a security decision, not a
 * preference.** The bearer gate (`BrokerTokenListener`) covers `/v1` and
 * nothing else, and this endpoint can start stacks and run arbitrary commands —
 * it is the most powerful surface the broker has. An `/mcp` outside `/v1` would
 * be an unauthenticated remote command execution endpoint, so the route lives
 * at `/v1/mcp` where the gate already applies. `/v1/mcp` matches the gate's
 * `str_starts_with('/v1/')` test exactly.
 *
 * `GET` and `DELETE` are accepted beside `POST` because the transport defines
 * them: `DELETE` ends a session, and `GET` opens the server-initiated stream.
 * `OPTIONS` is answered for preflight; the SDK's own default middleware stack
 * includes DNS-rebinding protection whose allowlist is localhost-only, so the
 * transport is built with an empty middleware list and CORS/host validation are
 * left to the reverse proxy.
 */
final class McpController
{
    /**
     * The path is a constant so the route and the alternative endpoints'
     * descriptions cannot disagree about where this lives.
     */
    public const string PATH = '/v1/mcp';

    public function __construct(
        private readonly McpServerFactory $factory,
        private readonly HttpFactory $httpFactory = new HttpFactory(),
    ) {
    }

    #[Route(self::PATH, name: 'mcp', methods: ['POST', 'GET', 'DELETE', 'OPTIONS'])]
    public function __invoke(Request $request): Response
    {
        if ('OPTIONS' === $request->getMethod()) {
            return new Response(status: Response::HTTP_NO_CONTENT);
        }

        $psrResponse = $this->factory->build()->run(
            new StreamableHttpTransport(
                $this->toPsr7($request),
                middleware: [],
            ),
        );

        return $this->toSymfony($psrResponse);
    }

    /**
     * Symfony request → PSR-7, built from the request's own parts.
     *
     * `server->all()` already carries Host among the CGI variables, and the
     * factory derives the Host header from the URI, so passing both would send
     * it twice — which the SDK's header handling rejects as a repeated header
     * rather than a duplicate value.
     */
    private function toPsr7(Request $request): PsrRequestInterface
    {
        $serverParams = $request->server->all();
        unset($serverParams['HTTP_HOST']);

        $psr = $this->httpFactory->createServerRequest(
            $request->getMethod(),
            $request->getUri(),
            $serverParams,
        );

        foreach ($request->headers->all() as $name => $values) {
            if ('host' === strtolower($name)) {
                continue;
            }

            foreach ($values as $value) {
                $psr = $psr->withAddedHeader($name, $value);
            }
        }

        $body = $request->getContent();

        if ('' !== $body) {
            $psr = $psr->withBody($this->httpFactory->createStream($body));
        }

        return $psr;
    }

    /**
     * PSR-7 response → Symfony response.
     *
     * Streamed bodies are wrapped rather than read, so a server-sent-events
     * response is forwarded as it is produced instead of being buffered until
     * the handler finishes. A body must never be coerced to a string here: the
     * SDK's `CallbackStream::__toString()` runs its callback, which writes SSE
     * frames straight to output and then returns `''` — by which point headers
     * are already on the wire and Symfony's own `send()` would fail.
     */
    private function toSymfony(PsrResponseInterface $response): Response
    {
        $headers = [];

        foreach ($response->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        $status = $response->getStatusCode();
        $body = $response->getBody();

        if (!$body->isSeekable() && !$response->hasHeader('Content-Length')) {
            return new StreamedResponse(
                static function () use ($body): void {
                    while (!$body->eof()) {
                        echo $body->read(8192);
                    }
                },
                $status,
                $headers,
            );
        }

        if ($body->isSeekable()) {
            $body->rewind();
        }

        return new Response((string) $body, $status, $headers);
    }
}
