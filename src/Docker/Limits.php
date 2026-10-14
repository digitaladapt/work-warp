<?php

declare(strict_types=1);

namespace App\Docker;

/**
 * What every command container is allowed to consume, and under whose user it
 * runs.
 *
 * Policy, not input. There is no request field that reaches any of this, which
 * is the point: the caller decides what to *run*, never what it runs *as* or
 * *with* (DOCKER-ACCESS.md §3, §5).
 *
 * The host also runs the calendar, the memory store and a dozen other services,
 * so a command that forks or allocates uncontrollably has to die at a limit
 * rather than take the machine with it.
 */
final class Limits
{
    /**
     * @param array<string, string> $tmpfs
     */
    public function __construct(
        public readonly int $memoryBytes = 1_073_741_824,
        public readonly int $nanoCpus = 1_000_000_000,
        public readonly int $pidsLimit = 512,
        public readonly int $stopTimeout = 10,
        /**
         * Added to the request's own timeout to get the `ww.ttl` label, so a
         * container the broker failed to reap is still eventually stopped.
         */
        public readonly int $ttlGrace = 300,
        /**
         * Numeric on purpose: a name would depend on the image having a user
         * with that name, and the workspace volume is owned by a number.
         */
        public readonly string $user = '1000:1000',
        public readonly array $tmpfs = ['/tmp' => 'rw,noexec,nosuid,size=64m'],
        /**
         * `RLIMIT_FSIZE` in bytes, or null for no cap. Configuration, like
         * `ttlGrace`, but defaulted here as well as in `config/services.yaml`,
         * so a `Limits` built by hand in a test or by another surface gets the
         * safe value rather than the permissive one.
         *
         * The one limit that bounds the *workspace*: everything else here
         * bounds the process, and the workspace is a mount. `FileSize` carries
         * the reasoning, including what this does not cover.
         */
        public readonly ?int $maxFileSizeBytes = FileSize::DEFAULT_BYTES,
        /**
         * How many bytes of each stream (`stdout` and `stderr`) are kept. The
         * reader stops at the cap rather than draining and discarding, and
         * the result carries `truncated` so a partial log cannot be mistaken
         * for a whole one (DOCKER-ACCESS.md §5, PLAN.md §5.1).
         *
         * Per stream, not per command: a command that fills both streams with
         * different content is two facts, and halving each to fit one budget
         * would cut the useful one to pay for the noisy one.
         */
        public readonly int $outputByteCap = 1_048_576,
    ) {
    }

    public static function defaults(): self
    {
        return new self();
    }

    /**
     * The `Ulimits` array for `POST /containers/create`.
     *
     * Soft and hard set to the same value on purpose: a hard limit above the
     * soft one would let a process raise its own soft limit up to it, and the
     * point of setting this at all is that nothing inside the container can
     * widen it. The keys are the string names the daemon expects (`fsize`),
     * not the numeric constants — measured, since the two spellings differ
     * between the API and the CLI.
     *
     * @return list<array{Name: string, Soft: int, Hard: int}>
     */
    public function ulimits(): array
    {
        if (null === $this->maxFileSizeBytes) {
            return [];
        }

        return [[
            'Name' => 'fsize',
            'Soft' => $this->maxFileSizeBytes,
            'Hard' => $this->maxFileSizeBytes,
        ]];
    }
}
