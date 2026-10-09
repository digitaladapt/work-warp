<?php

declare(strict_types=1);

namespace App\Exec;

/**
 * What a command did: its exit code, its two output streams, and the two
 * facts a caller must not have to infer — whether the output was cut, and
 * whether the command was killed at its deadline rather than ending by
 * itself.
 *
 * `exitCode` is nullable because the state can genuinely be unknown: a
 * container that was killed and did not settle inside the settling budget has
 * no exit code to report, and inventing one would be worse than saying so.
 */
final class ExecResult
{
    public function __construct(
        public readonly ?int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly bool $truncated,
        public readonly bool $timedOut,
    ) {
    }
}
