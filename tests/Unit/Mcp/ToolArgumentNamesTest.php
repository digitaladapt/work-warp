<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp;

use App\Mcp\McpPackageCatalogue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * A tool's JSON Schema and the PHP method behind it, held to one law: **the
 * schema's arguments are the handler's parameters, by name**.
 *
 * That is not a style preference, it is how the SDK dispatches a call.
 * `Mcp\Capability\Registry\ReferenceHandler::prepareArguments()` walks the
 * handler's parameters and takes each value from
 * `$arguments[$parameter->getName()]` — by name, never by position, with no
 * mapping layer between the wire name and the PHP name. A schema that
 * advertises `session` against a parameter named `$name` therefore has two
 * distinct failure modes, and the quiet one is worse:
 *
 * - A **required** argument with no matching parameter raises
 *   `RegistryException::internalError("Missing required argument ...")` before
 *   the handler is entered. Loud — this is what made `get_session`, `ps` and
 *   `delete_session` throw on every call.
 * - An **optional** argument with no matching parameter is not merely silent.
 *   The parameter falls back to its default, so the call succeeds and the flag is
 *   ignored. `keep_workspace` was advertised in the schema, documented in
 *   README.md as the way to keep a session's volumes, and dropped on the floor:
 *   `delete_session {"keep_workspace": true}` reported `workspace_removed: true`
 *   and destroyed the workspace it was asked to keep.
 *
 * The two laws below are stated over the catalogue rather than a hand-written
 * list of tool names, so a tool added later is covered without being named here
 * — which is the point. All ten were reachable by `McpApiTest`, but only
 * through `quick_command`, the one command tool whose parameter names happen to
 * match its schema; nothing called the other seven.
 *
 * The catalogue is the only input: `tools()` is a list and the handlers are
 * reflected by name, so this needs no container, no daemon and no mocks.
 *
 * @internal
 */
final class ToolArgumentNamesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array{string, string}, array<string, mixed>}>
     */
    public static function tools(): iterable
    {
        foreach ((new McpPackageCatalogue())->tools() as $tool) {
            yield $tool['name'] => [$tool['name'], $tool['handler'], $tool['inputSchema']];
        }
    }

    /**
     * The quiet half, and the stronger statement of it: the schema and the
     * handler name the *same set* of arguments. A property the handler does not
     * consume is a flag a caller can set and the broker cannot see; a parameter
     * the schema does not offer is an argument no caller can ever supply.
     *
     * @param array{string, string} $handler
     * @param array<string, mixed>  $schema
     */
    #[DataProvider('tools')]
    public function test_the_schema_advertises_exactly_the_arguments_the_handler_consumes(
        string $tool,
        array $handler,
        array $schema,
    ): void {
        $advertised = array_keys($schema['properties'] ?? []);
        $consumed = self::consumed($handler);

        foreach ($advertised as $property) {
            self::assertContains(
                $property,
                $consumed,
                \sprintf(
                    '`%s` advertises `%s`, but %s has no parameter of that name — the SDK takes each '
                    .'argument from `$arguments[$parameter->getName()]`, so the value would be dropped '
                    .'and any default would win silently. Handler parameters: %s.',
                    $tool,
                    $property,
                    self::describe($handler),
                    [] === $consumed ? '(none)' : implode(', ', $consumed),
                ),
            );
        }

        // Asserted as a set as well as per property, so the empty case is a real
        // assertion rather than a test that ran nothing (failOnRisky), and so a
        // parameter with no argument to fill it is caught too.
        self::assertEqualsCanonicalizing(
            $advertised,
            $consumed,
            \sprintf(
                '`%s` and %s disagree on the argument set: the schema offers [%s], the handler '
                .'consumes [%s].',
                $tool,
                self::describe($handler),
                implode(', ', $advertised),
                implode(', ', $consumed),
            ),
        );
    }

    /**
     * The loud half: an argument a caller *must* supply is one the handler cannot
     * default, and vice versa. A `required` entry with no matching parameter
     * means no call can succeed — the SDK raises "Missing required argument"
     * before the handler runs, which is exactly the failure that was reported.
     *
     * @param array{string, string} $handler
     * @param array<string, mixed>  $schema
     */
    #[DataProvider('tools')]
    public function test_the_schema_requires_exactly_the_arguments_the_handler_cannot_default(
        string $tool,
        array $handler,
        array $schema,
    ): void {
        $requiredBySchema = array_values($schema['required'] ?? []);
        $defaultless = self::defaultless($handler);

        foreach ($defaultless as $parameter) {
            self::assertContains(
                $parameter,
                $requiredBySchema,
                \sprintf(
                    '`%s` cannot supply `$%s` on %s: the schema requires only [%s], so every call '
                    .'raises "Missing required argument `%s`". Either rename the parameter to the '
                    .'argument\'s name or require that name.',
                    $tool,
                    $parameter,
                    self::describe($handler),
                    implode(', ', $requiredBySchema),
                    $parameter,
                ),
            );
        }

        self::assertEqualsCanonicalizing(
            $requiredBySchema,
            $defaultless,
            \sprintf(
                '`%s` and %s disagree on what is mandatory: the schema requires [%s], the handler '
                .'cannot default [%s]. A schema-required argument the handler defaults is merely '
                .'never enforced; a defaultless parameter the schema does not require cannot be '
                .'called at all.',
                $tool,
                self::describe($handler),
                implode(', ', $requiredBySchema),
                implode(', ', $defaultless),
            ),
        );
    }

    /**
     * The arguments a handler actually reads out of the JSON.
     *
     * A parameter with a non-builtin type is skipped: the SDK resolves those
     * through `InjectableParameters` before it looks for an argument, so they are
     * injected rather than supplied and are not the schema's business.
     *
     * @param array{string, string} $handler
     *
     * @return list<string>
     */
    private static function consumed(array $handler): array
    {
        $names = [];

        foreach (self::parameters($handler) as $parameter) {
            if (self::isInjected($parameter)) {
                continue;
            }

            $names[] = $parameter->getName();
        }

        return $names;
    }

    /**
     * The consumed arguments a caller has to supply — no default, not nullable.
     *
     * @param array{string, string} $handler
     *
     * @return list<string>
     */
    private static function defaultless(array $handler): array
    {
        $names = [];

        foreach (self::parameters($handler) as $parameter) {
            if (self::isInjected($parameter) || $parameter->isOptional()) {
                continue;
            }

            $names[] = $parameter->getName();
        }

        return $names;
    }

    /**
     * @param array{string, string} $handler
     *
     * @return list<ReflectionParameter>
     */
    private static function parameters(array $handler): array
    {
        return (new ReflectionMethod($handler[0], $handler[1]))->getParameters();
    }

    private static function isInjected(ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();

        return $type instanceof ReflectionNamedType && !$type->isBuiltin();
    }

    /**
     * `describe`, not `name`: PHPUnit's own `TestCase::name()` is final.
     *
     * @param array{string, string} $handler
     */
    private static function describe(array $handler): string
    {
        return $handler[0].'::'.$handler[1].'()';
    }
}
