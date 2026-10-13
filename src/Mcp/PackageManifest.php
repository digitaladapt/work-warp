<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Schema\Content\TextResourceContents;

/**
 * The package a client needs to call WorkWarp — served as `ww://pkg` and over
 * `GET /v1/pkg`, and the reason this PR is "the whole package" rather than
 * "an MCP endpoint".
 *
 * An MCP client can discover tools from the protocol alone (`tools/list`), so
 * the tool list is not strictly necessary here. What *is* necessary, and what
 * no protocol carries, is everything around the call:
 *
 * - **where the endpoint is** (`/v1/mcp`) and **that it is bearer-gated** —
 *   a client that does not know the token is required will read the `401` as a
 *   broken server;
 * - **what a session is** and that one must exist before `run_command` — the
 *   single most likely first mistake, and the one the tools cannot explain by
 *   their names alone;
 * - **the shape of the workspace**: persistence, the mount point, that `cmd`
 *   is an argv vector and not a shell string;
 * - **what is deliberately absent** — `stdin`, container mounts — so a caller
 *   reads those as decisions rather than as omissions to work around.
 *
 * So the manifest is the contract, and the tool list rides along in it as a
 * convenience. It is generated from the same catalogue the server is built
 * from, so it cannot describe a tool that is not there or miss one that is:
 * the drift this design has been careful about since §5a, applied to
 * documentation.
 */
final class PackageManifest
{
    public const string URI = 'ww://pkg';

    public const string MIME_TYPE = 'application/json';

    /**
     * The broker's own version, reported as the package's. A hardcoded string
     * rather than read from Composer: this describes the *running* build, and
     * the one place that is authoritative is the server info the SDK is given.
     */
    public function __construct(
        private readonly McpPackageCatalogue $catalogue,
        private readonly string $serverVersion,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => 'work-warp',
            'version' => $this->serverVersion,
            'description' => 'Runs commands and stacks in a persistent, per-session workspace, '
                .'inside a container the broker constrains. The workspace survives between calls; '
                .'nothing else does.',
            'protocol' => 'mcp',
            'endpoint' => McpController::PATH,
            'transport' => 'streamable-http',
            'auth' => [
                'scheme' => 'bearer',
                'header' => 'Authorization',
                'note' => 'Every /v1 path — including the MCP endpoint — requires WW_TOKEN as a '
                    .'bearer token. Health probes (/health, /ready) are open.',
            ],
            'quickstart' => [
                '1. create_session {"name": "my-work"} — makes the workspace volume (idempotent).',
                '2. run_command {"session": "my-work", "cmd": ["ls", "-la"]} — runs it.',
                '3. The workspace persists; the container does not. Use ps and get_session to see state.',
            ],
            'resources' => [
                ['uri' => self::URI, 'description' => 'This manifest.'],
            ],
            'concepts' => [
                'session' => 'A named, persistent workspace volume. It must exist before a command runs; '
                    .'create_session is safe to call before every piece of work.',
                'workspace' => 'Mounted at /workspace in every container. The only thing that persists.',
                'command' => 'One ephemeral container per call: /workspace and nothing else mounted, a '
                    .'read-only rootfs, no capabilities, a hard deadline, capped output.',
                'stack' => 'Multi-service, reachable by name on the session network. No port is ever '
                    .'published.',
            ],
            'not_available' => [
                'stdin' => 'Not deliverable on this build — it needs the Docker attach path. Write input '
                    .'to a file in the workspace and read it from the command.',
                'mounts' => 'Container mounts are unexpressible by design: the workspace is the only '
                    .'mount, and stack volumes are broker-created and named.',
                'host_network' => 'Only `none` and `session` exist. There is no way to name a network.',
            ],
            'tools' => array_map(
                static fn (array $tool): array => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'inputSchema' => $tool['inputSchema'],
                    'annotations' => null === $tool['annotations'] ? null : [
                        'title' => $tool['annotations']->title,
                        'readOnlyHint' => $tool['annotations']->readOnlyHint,
                        'destructiveHint' => $tool['annotations']->destructiveHint,
                        'idempotentHint' => $tool['annotations']->idempotentHint,
                        'openWorldHint' => $tool['annotations']->openWorldHint,
                    ],
                ],
                $this->catalogue->tools(),
            ),
        ];
    }

    /**
     * The `ww://pkg` resource read: the manifest, as text.
     *
     * Returns `TextResourceContents` rather than a raw array because that is
     * the shape a resource read answers with — the same shape ContextShuttle's
     * `count://stream` uses, so the two projects' resources agree on the
     * contract.
     */
    public function read(string $uri): TextResourceContents
    {
        return new TextResourceContents(
            uri: $uri,
            mimeType: self::MIME_TYPE,
            text: json_encode($this->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
        );
    }
}
