<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Build\BuildRequest;
use App\Build\BuildRunner;
use App\Session\SessionName;
use App\Stack\LogsResult;
use App\Stack\StackAction;
use App\Stack\StackRequest;
use App\Stack\StackRunner;
use App\Stack\StackServiceState;
use App\Stack\UpResult;
use App\Support\Utf8;
use InvalidArgumentException;

/**
 * `build_image`, `stack` and `reap` — the three operations that are not a
 * command, offered as tools.
 *
 * Each is a thin call into the object that already owns the operation, so the
 * tool layer adds a shape and a description and nothing else:
 *
 * - **`build_image`** takes a base64 tar context (the transport has no way to
 *   stream a body through JSON-RPC, and the HTTP endpoint's "the body *is* the
 *   context" has no analogue here). The context cap is the endpoint's, checked
 *   after decoding so a large context is refused by the broker rather than by
 *   the transport. Its result keeps the daemon's two shapes: a build that ran
 *   and failed is `image: null` with the diagnosis in `output`, and only a
 *   pre-stream refusal (the daemon would not start one) is an error.
 * - **`stack`** is the compose harness: one tool, three actions (`up`, `down`,
 *   `logs`), because the three are one resource's lifecycle and splitting them
 *   would triple the schema for no gain a model can act on. `up` takes the
 *   services map; `down` and `logs` do not.
 * - **`reap`** sweeps past-TTL containers now and returns the report. It is
 *   the janitor on demand, and it is explicitly *not* destructive in the way
 *   `delete_session` is: it acts only on containers whose own `ww.ttl` has
 *   expired, and it never removes a workspace volume.
 *
 * A `DockerException` reaching any of these is translated, once, by the shared
 * error handler — `DockerUnavailable` and `DockerRefused` both carry the
 * daemon's own words, and those words are the useful part.
 */
final class OpsTools
{
    public function __construct(
        private readonly BuildRunner $build,
        private readonly StackRunner $stack,
    ) {
    }

    /**
     * @return array{image: string|null, output: string, truncated: bool, timed_out: bool}
     */
    public function build(
        string $session,
        string $tag,
        string $context,
        ?string $network = null,
        ?int $timeout = null,
    ): array {
        // The tar arrives base64-encoded because JSON-RPC is text: the HTTP
        // endpoint streams the raw bytes as the body, and there is no body
        // here. Decoded strictly, then handed to the runner as a string, which
        // is one of the context shapes it accepts.
        $tar = base64_decode($context, true);

        if (false === $tar) {
            throw new InvalidArgumentException('context must be a base64-encoded tar archive (e.g. `tar -cf - . | base64`)');
        }

        $request = BuildRequest::fromQuery(array_filter([
            'tag' => $tag,
            'network' => $network,
            'timeout' => $timeout,
        ], static fn (mixed $value): bool => null !== $value));

        $result = $this->build->run(SessionName::from($session), $request, $tar);

        return [
            'image' => $result->image,
            'output' => Utf8::scrub($result->output),
            'truncated' => $result->truncated,
            'timed_out' => $result->timedOut,
        ];
    }

    /**
     * @param array<string, mixed>|null $services
     *
     * @return array<string, mixed>
     */
    public function stack(
        string $session,
        string $action,
        ?array $services = null,
        ?int $timeout = null,
        bool $keepVolumes = false,
    ): array {
        $request = StackRequest::fromArray([
            'action' => $action,
            'services' => $services ?? [],
            'timeout' => $timeout,
            'keep_volumes' => $keepVolumes,
        ]);

        $name = SessionName::from($session);

        return match ($request->action) {
            StackAction::Up => self::up($this->stack->up($name, $request)),
            StackAction::Down => self::down($this->stack->down($name, $request)),
            StackAction::Logs => self::logs($this->stack->logs($name, 1_048_576)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function up(UpResult $result): array
    {
        return [
            'services' => array_map(
                static fn (StackServiceState $s): array => [
                    'name' => $s->name,
                    'container' => $s->container,
                    'image' => $s->image,
                    'running' => $s->running,
                ],
                $result->services,
            ),
            'failure' => null === $result->failure ? null : [
                'service' => $result->failure['service'],
                'reason' => Utf8::scrub($result->failure['reason']),
            ],
            'timed_out' => $result->timedOut,
        ];
    }

    /**
     * The same keys the endpoint returns, for the same reason the command
     * result is: a `down` that mostly worked must not read like one that did
     * not, so failures are named per resource rather than collapsed.
     *
     * @return array<string, mixed>
     */
    private static function down(\App\Stack\DownResult $result): array
    {
        return [
            'containers_removed' => $result->containers,
            'volumes_removed' => $result->volumes,
            'failures' => array_map(
                static fn (array $failure): array => [
                    'name' => $failure['name'],
                    'reason' => Utf8::scrub($failure['reason']),
                ],
                $result->failures,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function logs(LogsResult $result): array
    {
        return [
            'output' => Utf8::scrub($result->output),
            'truncated' => $result->truncated,
        ];
    }
}
