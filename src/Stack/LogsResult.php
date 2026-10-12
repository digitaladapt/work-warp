<?php

declare(strict_types=1);

namespace App\Stack;

/**
 * What `logs` read: every stack service's output, in one artifact.
 *
 * The assembled text is the *product* here — PLAN.md §9's "logs captured as a
 * first-class artifact", because when an e2e fails the logs are usually the
 * actual answer. Each service gets a header line so the output is readable
 * without a second call, and the whole thing is capped the way every other
 * output in this API is: the reader stops rather than draining.
 */
final class LogsResult
{
    public function __construct(
        public readonly string $output,
        public readonly bool $truncated,
    ) {
    }
}
