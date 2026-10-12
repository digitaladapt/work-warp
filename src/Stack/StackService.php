<?php

declare(strict_types=1);

namespace App\Stack;

use App\Docker\ImageReference;
use App\Support\EnvMap;

/**
 * One service in a stack: an image, a command, an environment, its mounts and
 * its dependencies.
 *
 * This is the stack vocabulary's unit — the compose-altitude form of the
 * `ContainerSpec` argument. Every field here is either a bounded scalar or
 * another `Stack`-namespace type, and the fields that are *not* here are the
 * design: no `privileged`, no `cap_add`, no `devices`, no `pid`/`ipc`/`userns`
 * mode, no `network_mode`, no `ports`, no `volumes_from`, no `security_opt`,
 * no `restart`, no `user`, no `build` — because the broker fixes all of those,
 * and a caller has no field in which to ask.
 *
 * Three fields the *command* path does not have, and why each is justified
 * here rather than smuggled in:
 *
 * - **`command`** — a service is an image plus how to run it; without a
 *   command, a stack could only run images whose `CMD` happens to be right.
 *   It is an argv list, exactly like `exec`'s `cmd`: never a shell string,
 *   never re-parsed.
 * - **`health_cmd`** — `started` and `completed` are about the process, and
 *   neither says "the database is accepting connections". Without a health
 *   gate the alternative is a sleep in the harness — the thing everyone does
 *   when the vocabulary does not carry it. Also an argv list, so the same
 *   "arguments are never re-parsed" rule holds; a caller that needs a shell
 *   writes `["/bin/sh", "-c", …]` explicitly, which is a decision they made,
 *   not one the broker made for them.
 * - **`depends_on`** — start ordering and gates, resolved strictly against
 *   *this request's own service list*: a name that is not one of them is
 *   refused at construction, so a dependency can never point at a neighbour's
 *   container.
 */
final class StackService
{
    public const int MAX_COMMAND_ARGS = 128;
    public const int MAX_COMMAND_ARG_BYTES = 8192;
    public const int MAX_SERVICES = 16;
    public const int MAX_MOUNTS = 8;
    public const int MAX_DEPENDENCIES = 8;

    /**
     * The same shape a session name has, because a service name becomes a DNS
     * alias on the session network — `curl http://web:80` — and DNS labels are
     * exactly this character class. `\z`, not `$`: `$` would accept "web\n".
     */
    public const string NAME_PATTERN = '/^[a-z0-9][a-z0-9-]{0,30}\z/';

    /**
     * @param list<string>                          $command
     * @param array<string, string>                 $environment
     * @param list<StackMount>                      $mounts
     * @param list<array{string, ServiceCondition}> $dependsOn
     * @param list<string>                          $healthCmd
     */
    private function __construct(
        public readonly string $name,
        public readonly string $image,
        public readonly array $command,
        public readonly array $environment,
        public readonly array $mounts,
        public readonly array $dependsOn,
        public readonly array $healthCmd,
    ) {
    }

    /**
     * @param array<string, mixed> $data one element of the request's `services` map
     */
    public static function from(string $name, array $data): self
    {
        if (1 !== preg_match(self::NAME_PATTERN, $name)) {
            throw InvalidStackRequest::because(\sprintf('service names must match %s (lowercase letters, digits and dashes, at most 31 characters), got "%s"', self::NAME_PATTERN, $name));
        }

        $image = $data['image'] ?? null;

        if (!\is_string($image)) {
            throw InvalidStackRequest::because(\sprintf('service "%s": image is required and must be a string', $name));
        }

        if (null !== ($reason = ImageReference::validate($image))) {
            throw InvalidStackRequest::because(\sprintf('service "%s": %s', $name, $reason));
        }

        return new self(
            name: $name,
            image: $image,
            command: self::argvFrom($name, 'command', $data['command'] ?? null),
            environment: EnvMap::fromArray(
                $data['environment'] ?? null,
                static fn (string $reason): InvalidStackRequest => InvalidStackRequest::because(\sprintf('service "%s": %s', $name, $reason)),
            ),
            mounts: self::mountsFrom($name, $data['mounts'] ?? null),
            dependsOn: self::dependsOnFrom($name, $data['depends_on'] ?? null),
            healthCmd: self::argvFrom($name, 'health_cmd', $data['health_cmd'] ?? null),
        );
    }

    /**
     * An argv list: non-empty strings, no null bytes, each capped. The same
     * checks `exec`'s `cmd` gets, because they are the same kind of thing.
     *
     * @return list<string>
     */
    private static function argvFrom(string $service, string $field, mixed $value): array
    {
        if (null === $value) {
            return [];
        }

        if (!\is_array($value) || !array_is_list($value)) {
            throw InvalidStackRequest::because(\sprintf('service "%s": %s must be a list of strings — an argv array, never a shell string (use ["/bin/sh","-c","…"] if you want a shell)', $service, $field));
        }

        if (\count($value) > self::MAX_COMMAND_ARGS) {
            throw InvalidStackRequest::because(\sprintf('service "%s": %s must hold at most %d arguments, got %d', $service, $field, self::MAX_COMMAND_ARGS, \count($value)));
        }

        $argv = [];

        foreach ($value as $index => $argument) {
            if (!\is_string($argument) || '' === $argument) {
                throw InvalidStackRequest::because(\sprintf('service "%s": %s[%d] must be a non-empty string, got %s', $service, $field, $index, get_debug_type($argument)));
            }

            if (str_contains($argument, "\0")) {
                throw InvalidStackRequest::because(\sprintf('service "%s": %s[%d] must not contain a null byte', $service, $field, $index));
            }

            if (\strlen($argument) > self::MAX_COMMAND_ARG_BYTES) {
                throw InvalidStackRequest::because(\sprintf('service "%s": %s[%d] must be at most %d bytes', $service, $field, $index, self::MAX_COMMAND_ARG_BYTES));
            }

            $argv[] = $argument;
        }

        return $argv;
    }

    /**
     * @return list<StackMount>
     */
    private static function mountsFrom(string $service, mixed $value): array
    {
        if (null === $value) {
            return [];
        }

        if (!\is_array($value) || !array_is_list($value)) {
            throw InvalidStackRequest::because(\sprintf('service "%s": mounts must be a list of {volume, target, read_only?} objects', $service));
        }

        if (\count($value) > self::MAX_MOUNTS) {
            throw InvalidStackRequest::because(\sprintf('service "%s": at most %d mounts, got %d', $service, self::MAX_MOUNTS, \count($value)));
        }

        $mounts = [];

        foreach ($value as $index => $mount) {
            if (!\is_array($mount)) {
                throw InvalidStackRequest::because(\sprintf('service "%s": mounts[%d] must be an object', $service, $index));
            }

            $volume = $mount['volume'] ?? null;
            $target = $mount['target'] ?? null;
            $readOnly = $mount['read_only'] ?? false;

            if (!\is_string($volume)) {
                throw InvalidStackRequest::because(\sprintf('service "%s": mounts[%d].volume is required — a named volume of this stack; host paths are not expressible', $service, $index));
            }

            if (!\is_string($target)) {
                throw InvalidStackRequest::because(\sprintf('service "%s": mounts[%d].target is required', $service, $index));
            }

            if (!\is_bool($readOnly)) {
                throw InvalidStackRequest::because(\sprintf('service "%s": mounts[%d].read_only must be a boolean', $service, $index));
            }

            try {
                $mounts[] = StackMount::from(StackVolumeName::from($volume), $target, $readOnly);
            } catch (InvalidStackRequest $e) {
                throw InvalidStackRequest::because(\sprintf('service "%s": mounts[%d]: %s', $service, $index, $e->getMessage()));
            }
        }

        return $mounts;
    }

    /**
     * @return list<array{string, ServiceCondition}>
     */
    private static function dependsOnFrom(string $service, mixed $value): array
    {
        if (null === $value) {
            return [];
        }

        // Short form: a list of names, each meaning `started`.
        if (\is_array($value) && array_is_list($value)) {
            $value = array_fill_keys(
                array_map(static function (mixed $name) use ($service): string {
                    if (!\is_string($name)) {
                        throw InvalidStackRequest::because(\sprintf('service "%s": depends_on entries must be service names', $service));
                    }

                    return $name;
                }, $value),
                'started',
            );
        }

        if (!\is_array($value)) {
            throw InvalidStackRequest::because(\sprintf('service "%s": depends_on must be a list of service names or a map of {condition}', $service));
        }

        if (\count($value) > self::MAX_DEPENDENCIES) {
            throw InvalidStackRequest::because(\sprintf('service "%s": at most %d dependencies, got %d', $service, self::MAX_DEPENDENCIES, \count($value)));
        }

        $dependencies = [];

        foreach ($value as $name => $spec) {
            if (!\is_string($name) || 1 !== preg_match(self::NAME_PATTERN, $name)) {
                throw InvalidStackRequest::because(\sprintf('service "%s": depends_on names must be service names, got "%s"', $service, (string) $name));
            }

            if (\is_string($spec)) {
                $condition = $spec;
            } elseif (\is_array($spec)) {
                $condition = $spec['condition'] ?? 'started';

                if (!\is_string($condition)) {
                    throw InvalidStackRequest::because(\sprintf('service "%s": depends_on["%s"].condition must be a string', $service, $name));
                }
            } else {
                throw InvalidStackRequest::because(\sprintf('service "%s": depends_on["%s"] must be {"condition": …} or a condition string', $service, $name));
            }

            $parsed = ServiceCondition::tryFrom($condition);

            if (null === $parsed) {
                throw InvalidStackRequest::because(\sprintf('service "%s": depends_on["%s"] condition must be one of %s, got "%s"', $service, $name, implode(' | ', array_column(ServiceCondition::cases(), 'value')), $condition));
            }

            $dependencies[] = [$name, $parsed];
        }

        return $dependencies;
    }
}
