<?php

declare(strict_types=1);

namespace App\Tests\Unit\Session;

use App\Session\InvalidSessionName;
use App\Session\SessionName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class SessionNameTest extends TestCase
{
    public function test_a_plain_lowercase_name_is_accepted(): void
    {
        self::assertSame('refactor', SessionName::from('refactor')->value);
        self::assertSame('a', SessionName::from('a')->value, 'a single character is a legal name');
        self::assertSame('task-28', SessionName::from('task-28')->value);
    }

    public function test_the_longest_accepted_name_is_thirty_one_characters(): void
    {
        $name = str_repeat('a', 31);

        self::assertSame($name, SessionName::from($name)->value);
    }

    #[DataProvider('refusedNameProvider')]
    public function test_a_name_that_could_not_be_a_docker_namespace_is_refused(string $name): void
    {
        $this->expectException(InvalidSessionName::class);
        SessionName::from($name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedNameProvider(): iterable
    {
        yield 'uppercase' => ['Refactor'];
        yield 'leading dash' => ['-refactor'];
        yield 'a space' => ['my session'];
        yield 'an underscore' => ['my_session'];
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('a', 32)];
        yield 'a slash, which would imply a path' => ['a/b'];
        yield 'a dot, which would imply a domain' => ['a.b'];
        yield 'trailing newline' => ["refactor\n"];
    }

    public function test_every_resource_a_session_owns_is_named_from_one_place(): void
    {
        $session = SessionName::from('refactor');

        self::assertSame('ww-ws-refactor', $session->workspaceVolume());
        self::assertSame('ww-net-refactor', $session->network());
        self::assertSame('ww-refactor-0011223344', $session->containerName('0011223344'));
    }

    public function test_a_name_cannot_smuggle_in_a_second_resource(): void
    {
        // The pattern is anchored, so a crafted name cannot introduce a colon,
        // a slash or a space that would change what the derived names mean to
        // Docker. Each of these would be legal in a shell, in a label value, or
        // in a URL path — and none of them is legal here.
        $accepted = [];

        foreach (['a:b', 'a/b', 'a b', 'a\\b', "a\tb", '../../etc'] as $hostile) {
            try {
                $accepted[] = SessionName::from($hostile)->value;
            } catch (InvalidSessionName) {
                // Refused, which is the point of the test.
            }
        }

        self::assertSame([], $accepted, 'none of these may become a session name');
    }
}
