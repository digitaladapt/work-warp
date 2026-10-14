<?php

declare(strict_types=1);

namespace App\Docker;

use InvalidArgumentException;

/**
 * How large any one file a command writes may grow — `RLIMIT_FSIZE`, read from
 * the one environment variable that is a string because a size is not an int.
 *
 * **The gap this closes.** Every other limit the broker sets has a home
 * elsewhere: `Memory` and `NanoCpus` bound the process, `PidsLimit` bounds its
 * forks, the root filesystem is read-only and its only writable path is a
 * 64 MiB tmpfs. What none of those bound is the *workspace* — because the
 * workspace is a mount, and a mount is the one place where writing is the
 * point. So a command that loops with a redirect in it, the ordinary accident
 * rather than an interesting attack, can fill the host until something else on
 * it stops working.
 *
 * **Why an rlimit and not a quota.** A byte-cap on a volume is a filesystem
 * feature: XFS project quotas, or btrfs/zfs/devicemapper. Every one of them
 * needs host state a `docker compose up` cannot supply — a mount option, a
 * pre-formatted device, an `mkfs` that someone trying this out should not have
 * to make. `RLIMIT_FSIZE` is one `HostConfig` field, needs no host at all, and
 * is enforced by the kernel against the writer.
 *
 * **What it is.** Per file, per process: the kernel refuses a `write()` past
 * the limit with `EFBIG` and raises `SIGXFSZ`, so a runaway log file, a
 * runaway `dd` and a runaway install loop each die against it. A non-root
 * process cannot raise a hard rlimit, and a command runs as `1000:1000` with
 * every capability dropped, so it cannot be widened from inside the container.
 *
 * **What it is not.** Not a cap on the workspace as a whole, and worth saying
 * plainly because the two are easy to confuse. The worst case is this value
 * times the process limit — `5 GiB × 512 pids` — which does not bound the
 * disk, but does turn an overnight catastrophe into two numbers anyone can
 * read off a config file. And `truncate`/`fallocate` do not pass through
 * `write()`, so a program that preallocates with one of those is not bounded
 * here at all; `ww-base/smoke.sh` measures that rather than assuming it.
 *
 * **Where it applies.** Command containers only. A stack service is a declared
 * service rather than arbitrary code, and a database whose files grow past any
 * particular size is behaving correctly — capping it would break the case the
 * stack surface exists for. The build endpoint carries no `HostConfig` at all
 * (DOCKER-ACCESS.md §10), so a build container is unbounded by construction.
 * Both are choices, not oversights.
 */
final class FileSize
{
    /**
     * The default cap.
     *
     * Generous on purpose: it has to sit above any single artifact an ordinary
     * session produces — a tarball, a `docker save`, a repository's pack files
     * — and far below the host's free space, because what is being priced is
     * the accident, and an accident is not a dataset. A session that genuinely
     * needs a 40 GiB file raises this deliberately, which is the moment worth
     * making somebody think.
     */
    public const int DEFAULT_BYTES = 5 * 1024 ** 3;

    /**
     * Words that mean "no cap". Spelled, never a sentinel number: `0` is not
     * available for this because the kernel reads it as "no writes at all",
     * which is never the intent.
     *
     * @var list<string>
     */
    private const array UNLIMITED = ['unlimited', 'none', 'off'];

    /**
     * Suffixes, all binary, and `k`/`ki` deliberately the same thing: there is
     * no decimal variant here to confuse them with, and a limit that quietly
     * landed 7% below what the config says would be a surprise in the one place
     * this broker has a number doing real work.
     *
     * @var array<string, int>
     */
    private const array SUFFIXES = [
        'k' => 1024,
        'm' => 1024 ** 2,
        'g' => 1024 ** 3,
        't' => 1024 ** 4,
        'ki' => 1024,
        'mi' => 1024 ** 2,
        'gi' => 1024 ** 3,
        'ti' => 1024 ** 4,
    ];

    /**
     * Long enough for any real value, short enough that multiplying it cannot
     * overflow an int on a 64-bit build. A size past this is a typo, and a typo
     * that wrapped into a negative would disable the cap silently — the one
     * outcome worse than refusing to start.
     */
    private const int MAX_DIGITS = 15;

    public function __construct(
        public readonly ?int $bytes,
    ) {
    }

    /**
     * The safe value, for a caller that was given nothing: a `Limits` built by
     * hand in a test, or a surface added later that forgets to configure one.
     */
    public static function default(): self
    {
        return new self(self::DEFAULT_BYTES);
    }

    /**
     * Parse the configured value: a whole number with an optional `k`, `m`,
     * `g` or `t` suffix, or a word meaning no cap.
     *
     * An empty value — what an unset variable becomes — takes the default cap
     * rather than disabling it. That direction is chosen because the two ways
     * to be wrong are not equally bad: a deployment that meant "no limit" and
     * silently got 5 GiB sees a loud `EFBIG` naming the file, while a
     * deployment that meant "the default" and silently got no limit sees a
     * full disk and no clue which command did it.
     *
     * Parsed once at boot, from a service factory, so a typo here is a broker
     * that refuses to start rather than a first command that quietly ran
     * unbounded.
     *
     * @throws InvalidArgumentException on anything that is not a size
     */
    public static function parse(string $configured): self
    {
        $value = trim($configured);

        if ('' === $value) {
            return new self(self::DEFAULT_BYTES);
        }

        if (\in_array(strtolower($value), self::UNLIMITED, true)) {
            return new self(null);
        }

        if (1 !== preg_match('/^(?<size>\d{1,'.self::MAX_DIGITS.'})(?<unit>[a-z]{0,2})$/i', $value, $matches)) {
            throw self::notASize($configured);
        }

        $unit = strtolower($matches['unit']);

        if ('' !== $unit && !isset(self::SUFFIXES[$unit])) {
            throw self::notASize($configured);
        }

        $bytes = (int) $matches['size'] * (self::SUFFIXES[$unit] ?? 1);

        // Zero only reaches here as "0" or "0g": a soft limit of zero means
        // every `write()` fails, so it is a refusal, not a configuration.
        if ($bytes < 1) {
            throw self::notASize($configured);
        }

        return new self($bytes);
    }

    private static function notASize(string $configured): InvalidArgumentException
    {
        return new InvalidArgumentException(\sprintf(
            '"%s" is not a file size: expected a positive whole number with an optional k, m, g or t suffix '
            .'(for example 5G, 500m, 2048k), or "unlimited" to remove the cap entirely.',
            $configured,
        ));
    }
}
