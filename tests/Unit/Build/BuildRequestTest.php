<?php

declare(strict_types=1);

namespace App\Tests\Unit\Build;

use App\Build\BuildRequest;
use App\Build\InvalidBuildRequest;
use App\Exec\NetworkMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The build vocabulary: what a caller may say about a scratch build, and what
 * it may not.
 *
 * Construction is authoritative — an invalid request cannot exist as an
 * object — so these tests are the specification of the endpoint's `422` set.
 *
 * @internal
 */
final class BuildRequestTest extends TestCase
{
    public function test_a_tag_is_required_and_the_defaults_are_the_hermetic_ones(): void
    {
        $request = BuildRequest::fromQuery(['tag' => 'intl']);

        self::assertSame('intl', $request->tag->value);
        self::assertSame(NetworkMode::None, $request->network, 'the default must be the hermetic one, exactly as exec\'s is');
        self::assertSame(BuildRequest::DEFAULT_TIMEOUT, $request->timeout);
    }

    public function test_a_missing_or_empty_tag_is_refused_with_the_parameter_shown(): void
    {
        foreach ([[], ['tag' => ''], ['tag' => 42], ['tag' => null]] as $query) {
            try {
                BuildRequest::fromQuery($query);
                self::fail('a request without a usable tag must be refused');
            } catch (InvalidBuildRequest $e) {
                self::assertStringContainsString('tag is required', $e->getMessage());
                self::assertStringContainsString('?tag=intl', $e->getMessage(), 'the message shows the query form, because that is where parameters live');
            }
        }
    }

    /**
     * The tag pattern is the daemon's own reference grammar, not a guess: each
     * of these was probed against Docker 29.8 and refused by it with "invalid
     * reference format" (or "must be lowercase"). Refusing them here keeps the
     * 422 the caller's mistake and the 502 the daemon's.
     */
    #[DataProvider('refusedTags')]
    public function test_a_tag_the_daemon_would_refuse_is_refused_here_first(string $tag): void
    {
        $this->expectException(InvalidBuildRequest::class);

        BuildRequest::fromQuery(['tag' => $tag]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedTags(): iterable
    {
        yield 'uppercase' => ['WW-Bad'];
        yield 'trailing separator' => ['x-'];
        yield 'double separator' => ['x..y'];
        yield 'leading separator' => ['.x'];
        yield 'separator only' => ['.'];
        yield 'colon in the tag' => ['x:tag'];
        yield 'slash' => ['a/b'];
        yield 'a newline at the end, which `$` would have accepted' => ["intl\n"];
        yield 'empty' => [''];
        yield 'over forty characters' => [str_repeat('a', 41)];
    }

    public function test_a_tag_the_daemon_would_accept_is_accepted(): void
    {
        foreach (['intl', 'php85', 'php-8.5', 'x_y', str_repeat('a', 40)] as $tag) {
            self::assertSame($tag, BuildRequest::fromQuery(['tag' => $tag])->tag->value);
        }
    }

    public function test_network_is_the_same_two_valued_enum_exec_uses(): void
    {
        self::assertSame(NetworkMode::Session, BuildRequest::fromQuery(['tag' => 'x', 'network' => 'session'])->network);

        foreach (['host', 'bridge', 'public', 'ww-net-other'] as $value) {
            try {
                BuildRequest::fromQuery(['tag' => 'x', 'network' => $value]);
                self::fail('only none and session mean anything');
            } catch (InvalidBuildRequest $e) {
                self::assertStringContainsString('network must be one of none | session', $e->getMessage());
            }
        }
    }

    public function test_timeout_keeps_the_same_bounds_as_a_command(): void
    {
        self::assertSame(900, BuildRequest::fromQuery(['tag' => 'x', 'timeout' => '900'])->timeout);
        self::assertSame(BuildRequest::MAX_TIMEOUT, BuildRequest::fromQuery(['tag' => 'x', 'timeout' => BuildRequest::MAX_TIMEOUT])->timeout);

        foreach (['0', '-5', (string) (BuildRequest::MAX_TIMEOUT + 1), '1.5', "900\n"] as $value) {
            try {
                BuildRequest::fromQuery(['tag' => 'x', 'timeout' => $value]);
                self::fail('an out-of-bounds timeout must be refused: '.$value);
            } catch (InvalidBuildRequest) {
                // Which is the point — one refusal per bad value, asserted by
                // this loop rather than by an expectation that stops at the
                // first.
            }
        }
    }
}
