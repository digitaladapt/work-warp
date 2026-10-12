<?php

declare(strict_types=1);

namespace App\Docker;

/**
 * An image reference, as far as this broker will carry one.
 *
 * Deliberately a *bounded simplification* of Docker's reference grammar, and
 * the bounds are the point: the reference is validated at the edge so a
 * malformed one is the caller's `422` rather than a daemon `502`, and the
 * character set is closed so nothing surprising travels in a query string or
 * a JSON body.
 *
 * What is supported: lowercase name segments, an optional registry host with
 * a port on the first segment, an optional `:tag`. What is refused: uppercase
 * in the name (Docker's own rule), empty segments, a bare `:tag` with no name,
 * and `@sha256:` digests — each with a reason the caller can act on. Digests
 * are a *deferral*, not a statement of impossibility: supporting them means
 * threading a digest through the pull and create paths, which is its own
 * decision.
 *
 * The three functions are static and pure because nothing here constructs: a
 * validated reference is just its string, and a value object around it would
 * add a constructor call and no safety. `validate()` returns the refusal
 * rather than throwing it, so the caller — whose vocabulary owns the exception
 * type — can raise its own; that is the same shape as `EnvMap::fromArray`
 * taking the caller's exception builder, and it exists for the same reason: a
 * shared rule set must not decide which layer's error it is.
 */
final class ImageReference
{
    public const int MAX_LENGTH = 255;

    /**
     * Segments of lowercase alphanumerics with interior `._-`, joined by `/`.
     * An optional `:port` is allowed on the first segment only when a `/`
     * follows it (`registry:5000/app`, never `alpine:3` read as a port). One
     * optional `:tag` at the end, where uppercase is legal — Docker's rule.
     */
    private const string PATTERN = '/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?(?::[0-9]{1,5}(?=\/))?(?:\/[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?)*(?::[A-Za-z0-9_][A-Za-z0-9._-]{0,127})?\z/';

    /**
     * Null when the reference is one this broker will carry; otherwise the
     * reason, phrased for the caller.
     */
    public static function validate(string $reference): ?string
    {
        if ('' === $reference) {
            return 'an image reference must not be empty';
        }

        if (\strlen($reference) > self::MAX_LENGTH) {
            return \sprintf('image references are at most %d characters, got %d', self::MAX_LENGTH, \strlen($reference));
        }

        if (str_contains($reference, '@')) {
            return \sprintf('image reference "%s" uses a digest; digests are not supported yet — use a ":tag"', $reference);
        }

        if (1 !== preg_match(self::PATTERN, $reference)) {
            return \sprintf(
                'image reference "%s" is not one this broker will carry: lowercase name segments joined by "/", an optional registry port on the first segment, and an optional ":tag"',
                $reference,
            );
        }

        return null;
    }

    /**
     * The name half, for `POST /images/create?fromImage=`.
     *
     * Assumes `validate()` passed; a caller that skipped validation gets a
     * best-effort answer, not a second refusal — the refusal is validate's job
     * and having two of them is how they drift apart.
     */
    public static function name(string $reference): string
    {
        $tagPosition = self::tagPosition($reference);

        return null === $tagPosition ? $reference : substr($reference, 0, $tagPosition);
    }

    /**
     * The tag half, defaulting to `latest` the way the daemon does.
     */
    public static function tag(string $reference): string
    {
        $tagPosition = self::tagPosition($reference);

        return null === $tagPosition ? 'latest' : substr($reference, $tagPosition + 1);
    }

    /**
     * The position of the tag-separating `:`, or null when there is none.
     *
     * The separating colon is the one after the last `/` — a colon before a
     * slash is a registry port, not a tag. `alpine:3` has one;
     * `registry:5000/x` does not; `registry:5000/x:3` has one at the end.
     */
    private static function tagPosition(string $reference): ?int
    {
        $lastSlash = strrpos($reference, '/');
        $lastColon = strrpos($reference, ':');

        if (false === $lastColon) {
            return null;
        }

        if (false !== $lastSlash && $lastColon < $lastSlash) {
            return null;
        }

        return $lastColon;
    }
}
