<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * The environment-variable rules, one implementation for every vocabulary that
 * carries a caller-declared environment.
 *
 * Extracted when `stack` became the second caller: `exec` declared these rules
 * first, and two copies of "which keys, which caps, what is reserved" would
 * drift — the same reasoning that extracted `SessionNetwork`. The refusal
 * messages are the contract (tests assert them), so they moved verbatim, with
 * one deliberate generalisation: the broker-owned message no longer says "on
 * every command", because a stack service is not a command.
 *
 * The rules, and why each exists:
 *
 * - **Keys match `^[A-Za-z_][A-Za-z0-9_]*$`** — anchored with `\z`, because in
 *   PCRE `$` also matches before a trailing newline and `PATH\n` is not a key.
 * - **`WW_` is reserved** — the broker's namespace, refused so a caller cannot
 *   shadow broker-set state and quietly become a different principal.
 * - **The broker-owned names** (`PAGER`, `GIT_PAGER`, `TERM`, `NO_COLOR`,
 *   `CI`) are refused outright rather than deduplicated: glibc's `getenv`
 *   returns the *first* match, so a caller's earlier copy would beat the
 *   broker's later one — a guard that can be overridden is not a guard
 *   (PLAN.md §5.1).
 * - **Caps** on count and value length, because an environment is a budget
 *   like everything else.
 */
final class EnvMap
{
    public const int MAX_VARIABLES = 64;
    public const int MAX_VALUE_BYTES = 4096;

    /**
     * Anchored with `\z` rather than `$`: see the class docblock.
     */
    private const string KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*\z/';

    public const string RESERVED_PREFIX = 'WW_';

    /**
     * @param callable(string): InvalidArgumentException $invalid builds the caller's own refusal type
     *
     * @return array<string, string>
     */
    public static function fromArray(mixed $value, callable $invalid): array
    {
        if (null === $value) {
            return [];
        }

        if (!\is_array($value)) {
            throw $invalid(\sprintf('env must be an object of strings, got %s', get_debug_type($value)));
        }

        if (\count($value) > self::MAX_VARIABLES) {
            throw $invalid(\sprintf('env must hold at most %d variables, got %d', self::MAX_VARIABLES, \count($value)));
        }

        $env = [];

        foreach ($value as $name => $entry) {
            if (!\is_string($name) || 1 !== preg_match(self::KEY_PATTERN, $name)) {
                throw $invalid(\sprintf('env keys must match %s, got %s', self::KEY_PATTERN, \is_string($name) ? '"'.$name.'"' : get_debug_type($name)));
            }

            if (str_starts_with($name, self::RESERVED_PREFIX)) {
                throw $invalid(\sprintf('env keys beginning with %s are reserved for the broker, got "%s"', self::RESERVED_PREFIX, $name));
            }

            if (\array_key_exists($name, BrokerEnvironment::OWNED)) {
                throw $invalid(\sprintf('env["%s"] is owned by the broker (it sets %s itself wherever it captures output, PLAN.md §5.1); use a different variable', $name, implode(', ', array_map(static fn (string $key, string $value): string => $key.'='.$value, array_keys(BrokerEnvironment::OWNED), BrokerEnvironment::OWNED))));
            }

            if (!\is_string($entry)) {
                throw $invalid(\sprintf('env["%s"] must be a string, got %s', $name, get_debug_type($entry)));
            }

            if (str_contains($entry, "\0")) {
                throw $invalid(\sprintf('env["%s"] must not contain a null byte', $name));
            }

            if (\strlen($entry) > self::MAX_VALUE_BYTES) {
                throw $invalid(\sprintf('env["%s"] must be at most %d bytes', $name, self::MAX_VALUE_BYTES));
            }

            $env[$name] = $entry;
        }

        return $env;
    }
}
