<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Server;
use Mcp\Server\Session\Psr16SessionStore;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Assembles the official MCP SDK's server for use inside the Symfony request
 * cycle — the same shape ContextShuttle uses, pointed at this application.
 *
 * The SDK owns no socket and runs no event loop: `Server::run()` takes a
 * PSR-7 request-bearing transport and returns a PSR-7 response. The controller
 * supplies the request and sends the response, which is why there is no
 * transport code in this class.
 *
 * **Sessions are the one piece of state that outlives a request.** The
 * handshake (`initialize`, then later `tools/call`) legitimately spans two PHP
 * requests — under FrankenPHP as much as under any SAPI — so they are backed by
 * a dedicated cache pool rather than an in-memory store. The pool is built once
 * and shared, so every call site sees the same store.
 *
 * **Handlers are registered as `[class, method]` and resolved through PSR-11.**
 * That form is load-bearing: the SDK's `ReferenceHandler` treats a bare string
 * as a global function name and only the array form goes through the
 * container — which is what lets a tool be a service with constructor
 * dependencies (`ExecRunner`, `StackRunner`, the Docker client) rather than a
 * class that can be `new`ed with nothing.
 *
 * **The tool set is the catalogue's, not the SDK's discovery.** No directory
 * scanning: every tool is declared in `McpPackageCatalogue`, so what this
 * server offers is one readable list, and adding a tool cannot happen by
 * accident the way a magic-named method under a scanned directory can.
 */
final class McpServerFactory
{
    /** One hour, matching the SDK's documented default. */
    private const int SESSION_TTL_SECONDS = 3600;

    private ?Psr16SessionStore $sessionStore = null;

    /**
     * @param CacheItemPoolInterface $sessionPool the `mcp_sessions` cache pool (PSR-6, adapted below)
     */
    public function __construct(
        private readonly McpPackageCatalogue $catalogue,
        private readonly McpResourceRegistrar $resourceRegistrar,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly CacheItemPoolInterface $sessionPool,
        private readonly string $serverName,
        private readonly string $serverVersion,
    ) {
    }

    public function build(): Server
    {
        $builder = Server::builder()
            ->setServerInfo($this->serverName, $this->serverVersion)
            ->setInstructions(
                'WorkWarp runs commands and stacks in a persistent, per-session workspace. '
                .'Create a session first (create_session), then run_command inside it; the workspace '
                .'survives between calls and nothing else does. Read ww://pkg for the full contract.',
            )
            ->setContainer($this->container)
            ->setLogger($this->logger)
            ->setEventDispatcher($this->eventDispatcher)
            // Keeps the broker's own refusal messages in front of the model:
            // the SDK's default handling replaces anything that is not a
            // ToolCallException with a generic "Error while executing tool",
            // which would move "there is no session named X" into a log file.
            ->setReferenceHandler(new McpToolErrorTranslator(
                new ReferenceHandler($this->container),
            ))
            ->setSession($this->sessionStore());

        foreach ($this->catalogue->tools() as $tool) {
            $builder->addTool(
                handler: $tool['handler'],
                name: $tool['name'],
                description: $tool['description'],
                annotations: $tool['annotations'],
                inputSchema: $tool['inputSchema'],
            );
        }

        $this->resourceRegistrar->declareOn($builder);

        return $builder->build();
    }

    /**
     * Backing store for MCP sessions.
     *
     * The SDK's `Psr16SessionStore` wants PSR-16 while a Symfony cache pool is
     * PSR-6, so the pool is adapted here rather than at every call site. The
     * pool is the named `mcp_sessions` one rather than `cache.app`, so sessions
     * can be flushed or relocated on their own schedule without disturbing the
     * application cache.
     */
    public function sessionStore(): Psr16SessionStore
    {
        return $this->sessionStore ??= new Psr16SessionStore(
            cache: new Psr16Cache($this->sessionPool),
            prefix: 'mcp-session-',
            ttl: self::SESSION_TTL_SECONDS,
        );
    }
}
