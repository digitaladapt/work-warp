<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Text that may not be text, made safe to encode.
 *
 * A command's output, and a build's log, are arbitrary bytes: the daemon will
 * happily stream whatever a `RUN` printed. `JsonResponse` *throws* on invalid
 * UTF-8 rather than substituting — measured while building the exec client —
 * so every caller-visible string passes through here first.
 *
 * U+FFFD in the position of each invalid byte, via the one PHP tool that does
 * this without silent loss: `json_encode` with `JSON_INVALID_UTF8_SUBSTITUTE`,
 * decoded straight back. Checked against the alternative
 * (`mb_convert_encoding`) when this was first written, because mbstring
 * replaces with `?` by default — losing the distinction between "a byte that
 * was not text" and "a question mark the command actually printed".
 */
final class Utf8
{
    public static function scrub(string $text): string
    {
        if (1 === preg_match('//u', $text)) {
            return $text;
        }

        $scrubbed = json_decode(
            json_encode($text, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        return \is_string($scrubbed) ? $scrubbed : '';
    }
}
