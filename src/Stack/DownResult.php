<?php

declare(strict_types=1);

namespace App\Stack;

/**
 * What `down` removed, and what it could not.
 *
 * Best-effort by design, like the janitor: one container that refuses to go
 * must not stop the rest of the teardown. Failures are collected rather than
 * thrown, so the caller sees exactly which names need attention — and so a
 * `down` that mostly worked is not dressed up as one that did not.
 */
final class DownResult
{
    /**
     * @param list<string>                              $containers removed container names
     * @param list<string>                              $volumes    removed volume names
     * @param list<array{name: string, reason: string}> $failures
     */
    public function __construct(
        public readonly array $containers,
        public readonly array $volumes,
        public readonly array $failures,
    ) {
    }
}
