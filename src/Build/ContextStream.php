<?php

declare(strict_types=1);

namespace App\Build;

use InvalidArgumentException;

/**
 * The build context, as bytes the broker is willing to carry.
 *
 * The context arrives as a request body — a resource on a real request, or
 * any iterable in tests — and goes out again as the body of the daemon's
 * `POST /build`. This wraps it so the broker counts every byte that crosses
 * and refuses the moment the total would exceed the cap: an input of
 * unbounded size is a resource-exhaustion lever, and the endpoint is
 * reachable by anything holding the token.
 *
 * The refusal is raised *during* iteration, which is what makes it work for a
 * chunked request: there is no declared length to check up front, so the only
 * honest place to stop is mid-stream.
 */
final class ContextStream
{
    /**
     * Matches the daemon's own tar reader comfortably: large enough that a
     * build context does not become thousands of tiny reads, small enough
     * that one chunk is never a meaningful allocation.
     */
    private const int CHUNK_BYTES = 65_536;

    /**
     * @param resource|iterable<string>|string $source
     *
     * @return iterable<string>
     *
     * @throws BuildContextTooLarge when the total would exceed $maxBytes
     */
    public static function capped(mixed $source, int $maxBytes): iterable
    {
        // A string is one chunk, and is accepted for the same reason the
        // runner's signature accepts `mixed`: the real request hands over a
        // resource, and a test (or a caller building a small context in
        // memory) hands over the bytes. Both are "the context", and the cap
        // is enforced the same way for each.
        //
        // This method is deliberately NOT a generator itself: a function
        // containing `yield` anywhere defers its whole body, and `return
        // some_generator()` from one does not delegate — it yields nothing.
        // Each branch returns its own generator instead.
        if (\is_string($source)) {
            return self::fromString($source, $maxBytes);
        }

        if (\is_resource($source)) {
            return self::fromResource($source, $maxBytes);
        }

        if (is_iterable($source)) {
            return self::fromIterable($source, $maxBytes);
        }

        throw new InvalidArgumentException(\sprintf('the build context must be a string, a stream resource, or an iterable of strings, got %s', get_debug_type($source)));
    }

    /**
     * @return iterable<string>
     */
    private static function fromString(string $source, int $maxBytes): iterable
    {
        if (\strlen($source) > $maxBytes) {
            throw BuildContextTooLarge::because($maxBytes);
        }

        yield $source;
    }

    /**
     * @param resource $source
     *
     * @return iterable<string>
     */
    private static function fromResource(mixed $source, int $maxBytes): iterable
    {
        $total = 0;

        while (!feof($source)) {
            $chunk = fread($source, self::CHUNK_BYTES);

            if (false === $chunk || '' === $chunk) {
                break;
            }

            $total += \strlen($chunk);

            if ($total > $maxBytes) {
                throw BuildContextTooLarge::because($maxBytes);
            }

            yield $chunk;
        }
    }

    /**
     * @param iterable<mixed> $source
     *
     * @return iterable<string>
     */
    private static function fromIterable(iterable $source, int $maxBytes): iterable
    {
        $total = 0;

        foreach ($source as $index => $chunk) {
            if (!\is_string($chunk)) {
                throw new InvalidArgumentException(\sprintf('the build context must be an iterable of strings, got %s at index %s', get_debug_type($chunk), (string) $index));
            }

            $total += \strlen($chunk);

            if ($total > $maxBytes) {
                throw BuildContextTooLarge::because($maxBytes);
            }

            yield $chunk;
        }
    }
}
