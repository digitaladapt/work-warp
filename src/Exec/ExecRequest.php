<?php

declare(strict_types=1);

namespace App\Exec;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * One command to run, as typed input.
 *
 * The command itself is arbitrary — that is the product — and everything around
 * it is fixed. This object is the whole of what a caller may say about a
 * command container, which is why the container it produces cannot be
 * privileged: there is no field here, and therefore no field in the create
 * payload, for caps, mounts, devices, host namespaces or an arbitrary name
 * (DOCKER-ACCESS.md §2, §5a).
 *
 * Construction is authoritative: an invalid request cannot exist as an object,
 * which is what lets the rest of the codebase stop re-checking. The Assert
 * attributes restate the shape for tools that read attributes — the intended
 * path to a JSON Schema derived from this class rather than hand-written beside
 * it — while the checks below remain the authority.
 */
final class ExecRequest
{
    public const int DEFAULT_TIMEOUT = 1800;
    public const int MAX_TIMEOUT = 14400;
    public const int MAX_ENV_VARS = 64;
    public const int MAX_ENV_VALUE_BYTES = 4096;
    public const int MAX_STDIN_BYTES = 1048576;

    /**
     * The broker's reserved environment namespace.
     *
     * A caller may set the container's environment, which is not the sensitive
     * thing — the *broker's* environment is. But `WW_` names belong to the
     * broker, so declared keys under it are refused: otherwise a request could
     * shadow `WW_SESSION` and quietly become a different principal.
     */
    public const string RESERVED_ENV_PREFIX = 'WW_';

    /**
     * Environment the broker sets on every command, and the caller cannot
     * touch.
     *
     * These five are PLAN.md §5.1's direct fix for the failure that started
     * this project: a command that believes it has a human terminal opens a
     * pager and waits for keystrokes that never come. The guard only works if
     * it is not overridable — and "who wins" between two copies of the same
     * variable is not something to leave to the libc (glibc's `getenv`
     * returns the *first* match, so a caller's earlier copy would beat the
     * broker's later one). So the names are refused outright rather than
     * deduplicated, in the same spirit as `WW_`.
     *
     * @var array<string, string>
     */
    public const array BROKER_OWNED_ENV = [
        'PAGER' => 'cat',
        'GIT_PAGER' => 'cat',
        'TERM' => 'dumb',
        'NO_COLOR' => '1',
        'CI' => '1',
    ];

    /**
     * Anchored with `\z` rather than `$`: in PCRE, `$` also matches before a
     * trailing newline, so `/…$/` would accept "PATH\n" as an environment key.
     */
    private const string ENV_KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*\z/';

    /**
     * @param list<string>          $cmd
     * @param array<string, string> $env
     */
    private function __construct(
        #[Assert\Count(min: 1)]
        #[Assert\All([new Assert\Type(type: 'string')])]
        public readonly array $cmd,
        #[Assert\Range(min: 1, max: self::MAX_TIMEOUT)]
        public readonly int $timeout,
        public readonly NetworkMode $network,
        public readonly Workdir $workdir,
        #[Assert\Count(max: self::MAX_ENV_VARS)]
        #[Assert\All([new Assert\Type(type: 'string')])]
        public readonly array $env,
        public readonly ?string $stdin,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            cmd: self::cmdFrom($data['cmd'] ?? null),
            timeout: self::timeoutFrom($data['timeout'] ?? null),
            network: self::networkFrom($data['network'] ?? null),
            workdir: Workdir::from(self::stringOrNull($data['workdir'] ?? null) ?? Workdir::DEFAULT),
            env: self::envFrom($data['env'] ?? null),
            stdin: self::stdinFrom($data['stdin'] ?? null),
        );
    }

    /**
     * @return list<string>
     */
    private static function cmdFrom(mixed $value): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw InvalidExecRequest::because('cmd must be a list of strings, e.g. ["npm","test"]');
        }

        if ([] === $value) {
            throw InvalidExecRequest::because('cmd must contain at least one element');
        }

        $cmd = [];

        foreach ($value as $index => $argument) {
            if (!\is_string($argument)) {
                throw InvalidExecRequest::because(\sprintf('cmd[%d] must be a string, got %s', $index, get_debug_type($argument)));
            }

            if ('' === $argument) {
                throw InvalidExecRequest::because(\sprintf('cmd[%d] must not be empty', $index));
            }

            if (str_contains($argument, "\0")) {
                throw InvalidExecRequest::because(\sprintf('cmd[%d] must not contain a null byte', $index));
            }

            $cmd[] = $argument;
        }

        return $cmd;
    }

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
            throw InvalidExecRequest::because(\sprintf('timeout must be a whole number of seconds, got %s', get_debug_type($value)));
        }

        if ($timeout < 1 || $timeout > self::MAX_TIMEOUT) {
            throw InvalidExecRequest::because(\sprintf('timeout must be between 1 and %d seconds, got %d', self::MAX_TIMEOUT, $timeout));
        }

        return $timeout;
    }

    private static function networkFrom(mixed $value): NetworkMode
    {
        if (null === $value) {
            return NetworkMode::None;
        }

        if (!\is_string($value)) {
            throw InvalidExecRequest::because(\sprintf('network must be one of %s, got %s', implode(' | ', array_column(NetworkMode::cases(), 'value')), get_debug_type($value)));
        }

        $mode = NetworkMode::tryFrom($value);

        if (null === $mode) {
            throw InvalidExecRequest::because(\sprintf('network must be one of %s, got "%s"', implode(' | ', array_column(NetworkMode::cases(), 'value')), $value));
        }

        return $mode;
    }

    /**
     * @return array<string, string>
     */
    private static function envFrom(mixed $value): array
    {
        if (null === $value) {
            return [];
        }

        if (!\is_array($value)) {
            throw InvalidExecRequest::because(\sprintf('env must be an object of strings, got %s', get_debug_type($value)));
        }

        if (\count($value) > self::MAX_ENV_VARS) {
            throw InvalidExecRequest::because(\sprintf('env must hold at most %d variables, got %d', self::MAX_ENV_VARS, \count($value)));
        }

        $env = [];

        foreach ($value as $name => $entry) {
            if (!\is_string($name) || 1 !== preg_match(self::ENV_KEY_PATTERN, $name)) {
                throw InvalidExecRequest::because(\sprintf('env keys must match %s, got %s', self::ENV_KEY_PATTERN, \is_string($name) ? '"'.$name.'"' : get_debug_type($name)));
            }

            if (str_starts_with($name, self::RESERVED_ENV_PREFIX)) {
                throw InvalidExecRequest::because(\sprintf('env keys beginning with %s are reserved for the broker, got "%s"', self::RESERVED_ENV_PREFIX, $name));
            }

            if (\array_key_exists($name, self::BROKER_OWNED_ENV)) {
                throw InvalidExecRequest::because(\sprintf('env["%s"] is owned by the broker, which sets %s on every command so a pager can never wait for a terminal that is not there (PLAN.md §5.1); use a different variable', $name, implode(', ', array_map(static fn (string $key, string $value): string => $key.'='.$value, array_keys(self::BROKER_OWNED_ENV), self::BROKER_OWNED_ENV))));
            }

            if (!\is_string($entry)) {
                throw InvalidExecRequest::because(\sprintf('env["%s"] must be a string, got %s', $name, get_debug_type($entry)));
            }

            if (str_contains($entry, "\0")) {
                throw InvalidExecRequest::because(\sprintf('env["%s"] must not contain a null byte', $name));
            }

            if (\strlen($entry) > self::MAX_ENV_VALUE_BYTES) {
                throw InvalidExecRequest::because(\sprintf('env["%s"] must be at most %d bytes', $name, self::MAX_ENV_VALUE_BYTES));
            }

            $env[$name] = $entry;
        }

        return $env;
    }

    private static function stdinFrom(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw InvalidExecRequest::because(\sprintf('stdin must be a string, got %s', get_debug_type($value)));
        }

        if (\strlen($value) > self::MAX_STDIN_BYTES) {
            throw InvalidExecRequest::because(\sprintf('stdin must be at most %d bytes, got %d', self::MAX_STDIN_BYTES, \strlen($value)));
        }

        return $value;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw InvalidExecRequest::because(\sprintf('workdir must be a string, got %s', get_debug_type($value)));
        }

        return $value;
    }
}
