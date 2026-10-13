<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Mcp\Tools\CommandTools;
use App\Mcp\Tools\OpsTools;
use App\Mcp\Tools\ReapTool;
use App\Mcp\Tools\SessionTools;
use Mcp\Schema\ToolAnnotations;

/**
 * The MCP tool catalogue: what this server offers, and how each tool is shaped.
 *
 * The catalogue is a **list of definitions, not a framework**. Every tool here
 * is a call into an object that already existed — `ExecRunner`, `StackRunner`,
 * `SessionDestroyer`, `Janitor` — so the MCP surface adds a name, a JSON Schema
 * and a description, and nothing else. There is no MCP-specific business logic
 * and deliberately no second implementation of anything: a wrapper that
 * re-implemented the broker's rules would be a second place for them to be
 * wrong, which is the exact failure the design's "API first, wrapper last"
 * ordering existed to avoid (DOCKER-ACCESS.md §10, step 5).
 *
 * **Naming is `verb_noun`,** matching the rest of the estate's tools, because a
 * model chooses by name and `quick_command` versus `run_command` is a
 * distinction it can act on while `exec` versus `exec2` is not. The two command
 * tools are the one place a single operation is offered twice; the class
 * docblock on `CommandTools` says why and states that it is *not* a privilege
 * difference.
 *
 * **Annotations are declared per tool, never inferred,** and an unlisted tool
 * gets none — which reads as "unknown" rather than "safe". `delete_session` is
 * the only `destructiveHint: true` in the set, and that is the hint a client
 * uses to decide whether to ask before running it.
 *
 * The package's own description is served as a resource (`ww://pkg`) and over
 * HTTP (`GET /v1/pkg`); see `PackageManifest`.
 */
final class McpPackageCatalogue
{
    /**
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>, annotations: ?ToolAnnotations, handler: array{string, string}}>
     */
    public function tools(): array
    {
        return [
            [
                'name' => 'run_command',
                'description' => 'Run a command in a session\'s workspace. One ephemeral container per '
                    .'call, with the workspace volume mounted at /workspace and nothing else, a hard '
                    .'deadline, and capped output. The workspace persists between calls; the container '
                    .'does not, so a stuck process cannot survive. `cmd` is an argument vector, never a '
                    .'shell string: pass `["npm","test"]`, and use `["sh","-c","…"]` explicitly if you '
                    .'want shell parsing. stdin is not supported — write the input to a file in the '
                    .'workspace instead. An exit code is a result, not an error.',
                'inputSchema' => self::commandSchema(requireCmd: true, maxTimeout: 14400),
                'annotations' => new ToolAnnotations(
                    title: 'Run a command',
                    readOnlyHint: false,
                    destructiveHint: false,
                    idempotentHint: false,
                    openWorldHint: false,
                ),
                'handler' => [CommandTools::class, 'run'],
            ],
            [
                'name' => 'quick_command',
                'description' => 'Run a short command in a session\'s workspace — the same container as '
                    .'run_command with a one-minute ceiling and `cmd` defaulting to `true`. Use it as the '
                    .'cheap probe: does this workspace exist, does a command run at all. For real work, or '
                    .'for anything that may take minutes, use run_command.',
                'inputSchema' => self::commandSchema(requireCmd: false, maxTimeout: 60),
                'annotations' => new ToolAnnotations(
                    title: 'Quick command',
                    readOnlyHint: false,
                    destructiveHint: false,
                    idempotentHint: false,
                    openWorldHint: false,
                ),
                'handler' => [CommandTools::class, 'quick'],
            ],
            [
                'name' => 'create_session',
                'description' => 'Create a session, or adopt the one that already has this name. A session '
                    .'is a persistent workspace volume; creating one that exists is not an error, so this '
                    .'is safe to call before every piece of work. Answers whether it created the session.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'type' => 'string',
                            'description' => 'Lowercase letters, digits and dashes, at most 31 characters.',
                        ],
                    ],
                    'required' => ['name'],
                    'additionalProperties' => false,
                ],
                'annotations' => new ToolAnnotations(
                    title: 'Create a session',
                    readOnlyHint: false,
                    destructiveHint: false,
                    idempotentHint: true,
                    openWorldHint: false,
                ),
                'handler' => [SessionTools::class, 'create'],
            ],
            [
                'name' => 'list_sessions',
                'description' => 'List every session on this broker: each name and the workspace volume it '
                    .'owns. Sessions are discovered from the volumes themselves, so this reports what is '
                    .'really there rather than what anyone remembers creating.',
                'inputSchema' => self::emptySchema(),
                'annotations' => new ToolAnnotations(
                    title: 'List sessions',
                    readOnlyHint: true,
                    destructiveHint: false,
                    idempotentHint: true,
                    openWorldHint: false,
                ),
                'handler' => [SessionTools::class, 'list'],
            ],
            [
                'name' => 'get_session',
                'description' => 'Describe one session: its workspace volume, the containers it owns right '
                    .'now, and its ledger (the recent progress record kept inside the workspace, with '
                    .'today\'s entries inline and the earlier days listed by name). The ledger is '
                    .'best-effort — if it cannot be read the field is null and the rest of the answer is '
                    .'still true.',
                'inputSchema' => self::sessionSchema(),
                'annotations' => new ToolAnnotations(
                    title: 'Describe a session',
                    readOnlyHint: true,
                    destructiveHint: false,
                    idempotentHint: true,
                    openWorldHint: false,
                ),
                'handler' => [SessionTools::class, 'get'],
            ],
            [
                'name' => 'ps',
                'description' => 'List the containers a session owns right now — its running stack and any '
                    .'command container still in flight — with each one\'s kind, whether it is running, '
                    .'and which stack service it belongs to when it is one.',
                'inputSchema' => self::sessionSchema(),
                'annotations' => new ToolAnnotations(
                    title: 'List a session\'s containers',
                    readOnlyHint: true,
                    destructiveHint: false,
                    idempotentHint: true,
                    openWorldHint: false,
                ),
                'handler' => [SessionTools::class, 'ps'],
            ],
            [
                'name' => 'delete_session',
                'description' => 'Destroy a session: its containers (with their anonymous volumes), its '
                    .'network, and its volumes — the workspace last, because the workspace *is* the '
                    .'session and that removal cannot be undone. `keep_workspace` keeps every volume and '
                    .'removes only the live resources. Reports exactly what went, what was kept and what '
                    .'refused.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'session' => ['type' => 'string', 'description' => 'The session name.'],
                        'keep_workspace' => [
                            'type' => 'boolean',
                            'description' => 'Keep every volume this session owns (all or none) and remove '
                                .'only its containers and network. Default: false — nothing survives.',
                        ],
                    ],
                    'required' => ['session'],
                    'additionalProperties' => false,
                ],
                'annotations' => new ToolAnnotations(
                    title: 'Delete a session',
                    readOnlyHint: false,
                    destructiveHint: true,
                    idempotentHint: false,
                    openWorldHint: false,
                ),
                'handler' => [SessionTools::class, 'delete'],
            ],
            [
                'name' => 'build_image',
                'description' => 'Build a Docker image from a tar context (base64-encoded, since JSON-RPC '
                    .'carries text) and leave it tagged for this session as ww-<session>-<tag>. The image '
                    .'is disposable and unpushable. `network: session` lets a RUN reach the session\'s own '
                    .'network when the build genuinely needs to — most builds do not, and a cached base '
                    .'image avoids the question. A build that runs and fails returns image: null with the '
                    .'diagnosis in `output`; only a build the daemon refused to start is an error.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'session' => ['type' => 'string'],
                        'tag' => [
                            'type' => 'string',
                            'pattern' => '^[a-z0-9]+(?:[._-][a-z0-9]+)*$',
                            'description' => 'The image tag suffix. Becomes ww-<session>-<tag>:latest.',
                        ],
                        'context' => [
                            'type' => 'string',
                            'description' => 'The build context: a tar archive, base64-encoded '
                                .'(`tar -cf - . | base64`). Capped at 128 MiB before encoding.',
                        ],
                        'network' => [
                            'type' => 'string',
                            'enum' => ['none', 'session'],
                            'description' => 'Default: none. Use session only if a RUN needs the network.',
                        ],
                        'timeout' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => 14400,
                            'description' => 'Seconds before the build is cut off. Default: 1800.',
                        ],
                    ],
                    'required' => ['session', 'tag', 'context'],
                    'additionalProperties' => false,
                ],
                'annotations' => new ToolAnnotations(
                    title: 'Build an image',
                    readOnlyHint: false,
                    destructiveHint: false,
                    idempotentHint: false,
                    openWorldHint: true,
                ),
                'handler' => [OpsTools::class, 'build'],
            ],
            [
                'name' => 'stack',
                'description' => 'Bring a multi-service stack up, down, or into one log artifact, on the '
                    .'session\'s own network. One tool with three actions because they are one resource\'s '
                    .'lifecycle. Services are reachable by name from a run_command with network: session '
                    .'(http://web:80) — no port is ever published, so nothing collides and nothing binds '
                    .'the host. `up` takes a services map; `down` and `logs` do not. `down` removes stack '
                    .'volumes unless keep_volumes is true, because a leftover database is how two runs '
                    .'contaminate each other.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'session' => ['type' => 'string'],
                        'action' => [
                            'type' => 'string',
                            'enum' => ['up', 'down', 'logs'],
                            'description' => 'up brings the stack up; down tears it down; logs collects each service\'s output.',
                        ],
                        'services' => [
                            'type' => 'object',
                            'description' => 'For up: service name → {image, command?, environment?, '
                                .'mounts?, depends_on?}. Required for up, ignored otherwise.',
                            'additionalProperties' => ['type' => 'object'],
                        ],
                        'timeout' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => 14400,
                            'description' => 'Seconds the bring-up may take. Default: 1800.',
                        ],
                        'keep_volumes' => [
                            'type' => 'boolean',
                            'description' => 'For down: keep the stack\'s data volumes. Default: false.',
                        ],
                    ],
                    'required' => ['session', 'action'],
                    'additionalProperties' => false,
                ],
                'annotations' => new ToolAnnotations(
                    title: 'Run a stack',
                    readOnlyHint: false,
                    destructiveHint: false,
                    idempotentHint: false,
                    openWorldHint: true,
                ),
                'handler' => [OpsTools::class, 'stack'],
            ],
            [
                'name' => 'reap',
                'description' => 'Sweep now: stop containers running past their own TTL and remove ones '
                    .'already down past it, then report what was inspected, what was reaped and what '
                    .'refused. This never removes a workspace volume — only containers that outlived the '
                    .'life they were given. Safe to call at any time; running it twice finishes what the '
                    .'first pass started.',
                'inputSchema' => self::emptySchema(),
                'annotations' => new ToolAnnotations(
                    title: 'Reap past-TTL containers',
                    readOnlyHint: false,
                    destructiveHint: false,
                    idempotentHint: true,
                    openWorldHint: false,
                ),
                'handler' => [ReapTool::class, 'reap'],
            ],
        ];
    }

    /**
     * The command tools' schema: the same field table the HTTP endpoint
     * documents, expressed so the SDK validates a call before a handler sees
     * it. `minItems: 1` on `cmd` and the timeout bounds are the request
     * object's own rules restated — not a second source of truth, but the
     * first line of defence whose failure mode is a readable validation error
     * rather than an exception from deep in the runner.
     *
     * @return array<string, mixed>
     */
    private static function commandSchema(bool $requireCmd, int $maxTimeout): array
    {
        $properties = [
            'session' => ['type' => 'string', 'description' => 'The session whose workspace the command runs in.'],
            'cmd' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'minLength' => 1],
                'minItems' => 1,
                'description' => 'The command as an argument vector, e.g. ["npm","test"]. Never a shell '
                    .'string: for shell syntax pass ["sh","-c","…"] explicitly. Arguments are never '
                    .'re-parsed.',
            ],
            'workdir' => [
                'type' => 'string',
                'description' => 'Directory inside the workspace, e.g. "packages/api". A path that escapes '
                    .'the workspace is refused, not normalised. Default: the workspace root.',
            ],
            'timeout' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => $maxTimeout,
                'description' => 'Seconds before the command is killed. Defaults differ per tool; the '
                    .'broker kills at expiry and reports exit 137 (or null if it did not settle).',
            ],
            'network' => [
                'type' => 'string',
                'enum' => ['none', 'session'],
                'description' => 'Default: none. Use session to reach this session\'s own stack by '
                    .'service name; there is no other value and no way to name a network.',
            ],
            'env' => [
                'type' => 'object',
                'additionalProperties' => ['type' => 'string'],
                'description' => 'Extra environment for the command. Only what you declare is passed — '
                    .'nothing is inherited. Names beginning ww_ or WW_ are reserved.',
            ],
        ];

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $requireCmd ? ['session', 'cmd'] : ['session'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function sessionSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'session' => ['type' => 'string', 'description' => 'The session name.'],
            ],
            'required' => ['session'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptySchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
            'additionalProperties' => false,
        ];
    }
}
