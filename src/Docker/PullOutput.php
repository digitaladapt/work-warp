<?php

declare(strict_types=1);

namespace App\Docker;

/**
 * What reading an image pull's stream produced.
 *
 * The same four facts a build's read produces, for the same reasons: pulls are
 * streams of JSON progress lines that can carry `{"error":…}`, can stall, and
 * can run long. Only the *keeping* differs — a pull's useful remainder is its
 * errors (the progress lines are noise), while the success of a pull is never
 * decided by text at all: the runner confirms it with `imageExists`, exactly
 * as the build path does. That is the same rule as everywhere else — the
 * outcome is asked, not parsed.
 */
final class PullOutput
{
    public function __construct(
        public readonly string $output,
        public readonly bool $truncated,
        public readonly bool $timedOut,
        public readonly bool $failed,
    ) {
    }
}
