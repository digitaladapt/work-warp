<?php

declare(strict_types=1);

namespace App\Tests\Unit\Docker;

use App\Docker\FileSize;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one limit that bounds the workspace rather than the process, and the
 * parse that turns a configured string into it.
 *
 * The parse is tested rather than the wiring that calls it, for the same
 * reason the rest of this suite is: what the broker *builds* is assertable
 * without a daemon. The daemon-side question — does `fsize` actually stop a
 * write — is a measurement, not an assertion, and lives in `ww-base/smoke.sh`
 * beside the other things only a real daemon can answer.
 *
 * @internal
 */
final class FileSizeTest extends TestCase
{
    #[DataProvider('sizes')]
    public function test_a_configured_size_parses_to_bytes(string $configured, int $bytes): void
    {
        self::assertSame($bytes, FileSize::parse($configured)->bytes);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function sizes(): iterable
    {
        yield 'a bare number is bytes' => ['1048576', 1_048_576];
        yield 'k' => ['2048k', 2 * 1024 ** 1 * 1024];
        yield 'm' => ['500m', 500 * 1024 ** 2];
        yield 'g' => ['5G', 5 * 1024 ** 3];
        yield 't' => ['2t', 2 * 1024 ** 4];
        yield 'ki spelled the long way' => ['8ki', 8 * 1024];
        yield 'gi spelled the long way' => ['1gi', 1024 ** 3];
        yield 'case does not matter' => ['5g', 5 * 1024 ** 3];
        yield 'surrounding space does not matter' => ['  5G  ', 5 * 1024 ** 3];
    }

    /**
     * Binary, not decimal, and asserted because the difference is 7% at the
     * gigabyte and nothing in the string says which one was meant: `5G` is
     * 5368709120, not 5000000000.
     */
    public function test_the_unit_is_binary_not_decimal(): void
    {
        self::assertSame(5_368_709_120, FileSize::parse('5G')->bytes);
        self::assertNotSame(5_000_000_000, FileSize::parse('5G')->bytes);
    }

    #[DataProvider('unlimited')]
    public function test_a_word_meaning_no_cap_parses_to_no_cap(string $configured): void
    {
        self::assertNull(FileSize::parse($configured)->bytes);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unlimited(): iterable
    {
        yield 'unlimited' => ['unlimited'];
        yield 'none' => ['none'];
        yield 'off' => ['off'];
        yield 'and in any case' => ['UNLIMITED'];
    }

    /**
     * An empty value is what an unset variable becomes, and it takes the
     * default rather than disabling the cap. The two mistakes are not
     * symmetrical — see `FileSize` — so the permissive reading is not allowed
     * to win by accident.
     */
    public function test_an_empty_value_takes_the_default_rather_than_removing_the_cap(): void
    {
        self::assertSame(FileSize::DEFAULT_BYTES, FileSize::parse('')->bytes);
        self::assertSame(FileSize::DEFAULT_BYTES, FileSize::parse('   ')->bytes);
    }

    /**
     * Every one of these is a typo, and a typo must be a broker that refuses
     * to start rather than a first command that quietly ran unbounded. Zero is
     * in the list because the kernel reads a soft limit of zero as "no writes
     * at all", which is never what the word was meant to say.
     */
    #[DataProvider('refusals')]
    public function test_anything_that_is_not_a_size_is_refused(string $configured): void
    {
        $this->expectException(InvalidArgumentException::class);

        FileSize::parse($configured);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusals(): iterable
    {
        yield 'zero' => ['0'];
        yield 'zero with a unit' => ['0g'];
        yield 'a bare unit' => ['g'];
        yield 'a decimal' => ['1.5G'];
        yield 'a negative' => ['-1G'];
        yield 'an unknown unit' => ['5x'];
        yield 'a unit that is a word' => ['5gigabytes'];
        yield 'a size that overflows an int' => ['99999999999999999999'];
        yield 'prose' => ['five gigabytes'];
    }

    /**
     * The refusal names the value, because the person reading it is looking at
     * a config file and needs to know which line.
     */
    public function test_the_refusal_names_the_value_it_could_not_read(): void
    {
        $this->expectExceptionMessage('"1.5G" is not a file size');

        FileSize::parse('1.5G');
    }

    public function test_the_default_is_the_documented_cap(): void
    {
        self::assertSame(5 * 1024 ** 3, FileSize::DEFAULT_BYTES);
        self::assertSame(FileSize::DEFAULT_BYTES, FileSize::default()->bytes);
    }
}
