<?php

declare(strict_types=1);

namespace App\Build;

/**
 * What reading a build's stream produced, before the runner decides whether
 * an image is really there.
 *
 * This is the transport-level half of a build: the daemon's output, trimmed to
 * the retention cap, and the three facts about *how the read ended* — whether
 * anything was cut, whether the broker's clock cut it, and whether the daemon
 * reported a failure. The runner adds the one fact a stream cannot answer
 * honestly on its own (whether the tagged image exists), which is why there
 * are two objects rather than one with half-filled fields.
 *
 * `failed` exists because the image lookup alone cannot tell \"this build
 * produced that tag\" from \"that tag already existed\": a rebuild of the same
 * scratch tag over an older image would otherwise report the *old* image as
 * the result of a failed build. So a reported failure is taken at its word and
 * the lookup is skipped.
 */
final class BuildOutput
{
    public function __construct(
        public readonly string $output,
        public readonly bool $truncated,
        public readonly bool $timedOut,
        public readonly bool $failed,
    ) {
    }
}
