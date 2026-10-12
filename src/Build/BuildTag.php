<?php

declare(strict_types=1);

namespace App\Build;

/**
 * A caller's tag for a scratch build, before it becomes an image reference.
 *
 * The full reference is `ww-<session>-<tag>` and is built by `SessionName`, so
 * `tag` here is the caller's *suffix* — never a full name, and never anything
 * that could change which repository the image lands in. A caller names the
 * image it wants; it does not name a place to put it.
 *
 * The pattern is deliberately stricter than the field table in DOCKER-ACCESS.md
 * §5 (`[a-z0-9._-]{1,40}`): the daemon's own reference grammar is
 * `[a-z0-9]+(?:[._-][a-z0-9]+)*` — separators must sit *between* alphanumerics,
 * and a name may not start or end with one. Measured against Docker 29.8 by
 * probing `/build` directly: `ww-x-`, `ww-x..y`, `ww.` and `WW-Bad` are all
 * refused by the daemon ("invalid reference format", "must be lowercase")
 * while `ww-x_ok` is accepted. Refusing them here rather than letting the
 * daemon refuse them later keeps the 422 the caller's mistake and the 502 the
 * daemon's.
 */
final class BuildTag
{
    /**
     * 40 characters, matching the field table — the session prefix is on top
     * of this, and a Docker repository name may be 255 in total.
     */
    public const int MAX_LENGTH = 40;

    private const string PATTERN = '/^[a-z0-9]+(?:[._-][a-z0-9]+)*\z/';

    private function __construct(public readonly string $value)
    {
    }

    public static function from(string $tag): self
    {
        if (\strlen($tag) > self::MAX_LENGTH) {
            throw InvalidBuildRequest::because(\sprintf('tag must be at most %d characters, got %d', self::MAX_LENGTH, \strlen($tag)));
        }

        // \z rather than $: in PCRE, `$` also matches before a trailing
        // newline, so `/…$/` would accept "intl\n" and put a newline into an
        // image reference.
        if (1 !== preg_match(self::PATTERN, $tag)) {
            throw InvalidBuildRequest::because(\sprintf('tag must match %s (lowercase letters, digits, and separators between them), got "%s"', self::PATTERN, $tag));
        }

        return new self($tag);
    }
}
