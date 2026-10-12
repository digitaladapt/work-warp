<?php

declare(strict_types=1);

namespace App\Build;

/**
 * What a build did, as the endpoint reports it.
 *
 * `image` and `timedOut` are independent facts, on purpose:
 *
 * - `image` is non-null exactly when the tagged image exists *now*, which the
 *   runner asks the daemon rather than parsing out of the log. Tagging only
 *   ever happens for a successful build, so an image that exists means the
 *   build completed — even if the broker's budget cut the *stream* in the
 *   same instant. A build that ran and failed has `image: null` and the
 *   daemon's own diagnosis in `output`.
 * - `timedOut` is about how the *read* ended: the budget expired and the
 *   broker dropped the connection, which the daemon documents as cancelling
 *   the build. `output` keeps whatever had been streamed before the cut —
 *   partial output beats none — though a build cut early will have little.
 *
 * A build that ran and failed is a *result* (200), not a 5xx — the same rule
 * as a killed command: the broker worked, and the caller needs to tell that
 * apart from "the broker broke".
 */
final class BuildResult
{
    public function __construct(
        public readonly ?string $image,
        public readonly string $output,
        public readonly bool $truncated,
        public readonly bool $timedOut,
    ) {
    }
}
