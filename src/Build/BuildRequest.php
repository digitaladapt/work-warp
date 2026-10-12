<?php

declare(strict_types=1);

namespace App\Build;

use App\Exec\NetworkMode;

/**
 * One scratch build, as typed input.
 *
 * The equivalent of `ExecRequest` for `/build`: everything around the context
 * is fixed, and this object is the whole of what a caller may say about the
 * build. The context itself (the tar) is *not* part of this object — it is a
 * stream, not a value, and it never influences any of the fields below.
 *
 * Construction is authoritative, as everywhere in this codebase: an invalid
 * build request cannot exist as an object.
 *
 * The fields come from the wire as query parameters rather than a JSON body,
 * and that is deliberate: the request body *is* the build context, so there is
 * no JSON body to put parameters in. It is also the shape the daemon itself
 * uses (`POST /build?t=…&networkmode=…` with the tar as the body), so the
 * broker's edge and its upstream agree about where each thing lives.
 */
final class BuildRequest
{
    public const int DEFAULT_TIMEOUT = 1800;
    public const int MAX_TIMEOUT = 14400;

    /**
     * The largest build context this broker will stream to the daemon.
     *
     * Not a Docker limit — a policy cap, in the same family as the output and
     * stdin caps: an input of unbounded size is a resource-exhaustion lever,
     * and `POST /v1/sessions/{name}/build` is reachable by anything holding the
     * token. 128 MiB comfortably fits a project tar without its dependency
     * directories (measured: this repo is 1.3 MiB, task-loom 4.1 MiB without
     * `.git`/`vendor`/`var` — and a context that needs its `.git` is not a
     * build, it is an upload). Enforced twice: on the declared
     * `Content-Length` when there is one, and by counting bytes as they stream
     * when there is not.
     */
    public const int MAX_CONTEXT_BYTES = 134_217_728;

    private function __construct(
        public readonly BuildTag $tag,
        public readonly NetworkMode $network,
        public readonly int $timeout,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        return new self(
            tag: self::tagFrom($query['tag'] ?? null),
            network: self::networkFrom($query['network'] ?? null),
            timeout: self::timeoutFrom($query['timeout'] ?? null),
        );
    }

    private static function tagFrom(mixed $value): BuildTag
    {
        if (!\is_string($value) || '' === $value) {
            throw InvalidBuildRequest::because('tag is required and must be a string, e.g. ?tag=intl');
        }

        return BuildTag::from($value);
    }

    private static function networkFrom(mixed $value): NetworkMode
    {
        if (null === $value) {
            return NetworkMode::None;
        }

        if (!\is_string($value)) {
            throw InvalidBuildRequest::because(\sprintf('network must be one of %s, got %s', implode(' | ', array_column(NetworkMode::cases(), 'value')), get_debug_type($value)));
        }

        $mode = NetworkMode::tryFrom($value);

        if (null === $mode) {
            throw InvalidBuildRequest::because(\sprintf('network must be one of %s, got "%s"', implode(' | ', array_column(NetworkMode::cases(), 'value')), $value));
        }

        return $mode;
    }

    /**
     * The timeout is an addition to the field table, and it is the project's
     * spine applied to builds: "cannot sit there forever". Nothing in the
     * daemon bounds a classic build, so the broker bounds it itself — the HTTP
     * call carries the budget, and a build that overruns is cut off at the
     * connection (which the daemon documents as cancelling the build). Same
     * bounds as a command, so there is one timeout vocabulary rather than two.
     */
    private static function timeoutFrom(mixed $value): int
    {
        if (null === $value) {
            return self::DEFAULT_TIMEOUT;
        }

        if (\is_int($value)) {
            $timeout = $value;
        } elseif (\is_string($value) && 1 === preg_match('/^\d+\z/', $value)) {
            $timeout = (int) $value;
        } else {
            throw InvalidBuildRequest::because(\sprintf('timeout must be a whole number of seconds, got %s', get_debug_type($value)));
        }

        if ($timeout < 1 || $timeout > self::MAX_TIMEOUT) {
            throw InvalidBuildRequest::because(\sprintf('timeout must be between 1 and %d seconds, got %d', self::MAX_TIMEOUT, $timeout));
        }

        return $timeout;
    }
}
