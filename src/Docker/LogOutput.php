<?php

declare(strict_types=1);

namespace App\Docker;

/**
 * What a command wrote, as two streams and one honesty flag.
 *
 * `truncated` is not decoration: it is the difference between "this is the
 * output" and "this is the first part of the output", and a caller that cannot
 * tell those apart will quietly act on half a log. The caps live in the client
 * that fills this in; this type only carries the result.
 */
final class LogOutput
{
    public function __construct(
        public readonly string $stdout = '',
        public readonly string $stderr = '',
        public readonly bool $truncated = false,
    ) {
    }
}
