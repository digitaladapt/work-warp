<?php

declare(strict_types=1);

namespace App\Tests\Unit\Stack;

use App\Stack\InvalidStackRequest;
use App\Stack\ServiceCondition;
use App\Stack\StackAction;
use App\Stack\StackRequest;
use App\Stack\StackService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The stack vocabulary: what a caller may say about a stack, and — the part
 * that carries the design — what it may not.
 *
 * This is the compose-altitude form of `ContainerSpecTest`'s argument: a
 * compose file can say `privileged: true`, `network_mode: host`,
 * `volumes: [/:/host]`, `devices:`, `cap_add:`, `ports:`, `restart:`. Nothing
 * in this test can say any of them, because nothing in the vocabulary has a
 * field for them — and the refusals that *are* asserted are the grammar
 * bounds, not a denylist.
 *
 * @internal
 */
final class StackRequestTest extends TestCase
{
    public function test_an_up_request_takes_its_services_and_defaults(): void
    {
        $request = StackRequest::fromArray([
            'action' => 'up',
            'services' => ['web' => ['image' => 'nginx:alpine']],
        ]);

        self::assertSame(StackAction::Up, $request->action);
        self::assertArrayHasKey('web', $request->services);
        self::assertSame('nginx:alpine', $request->services['web']->image);
        self::assertSame(StackRequest::DEFAULT_TIMEOUT, $request->timeout);
        self::assertFalse($request->keepVolumes);
    }

    public function test_down_and_logs_carry_no_services_even_if_sent(): void
    {
        // Ignoring unknown fields is deliberate everywhere in this codebase: a
        // client sending a superset should not be told its request is
        // malformed, and nothing is created from the ignored part.
        $down = StackRequest::fromArray(['action' => 'down', 'services' => ['web' => ['image' => 'nginx']]]);

        self::assertSame([], $down->services);
        self::assertFalse($down->keepVolumes);

        $keep = StackRequest::fromArray(['action' => 'down', 'keep_volumes' => true]);
        self::assertTrue($keep->keepVolumes);
    }

    public function test_the_action_is_required_and_closed(): void
    {
        foreach ([null, 'serve', 'UP', 42] as $bad) {
            try {
                StackRequest::fromArray(['action' => $bad]);
                self::fail('a bad action must be refused');
            } catch (InvalidStackRequest $e) {
                self::assertStringContainsString('action', $e->getMessage());
            }
        }
    }

    public function test_up_without_services_is_refused_with_the_shape_shown(): void
    {
        try {
            StackRequest::fromArray(['action' => 'up', 'services' => []]);
            self::fail('up without services must be refused');
        } catch (InvalidStackRequest $e) {
            self::assertStringContainsString('services is required for `up`', $e->getMessage());
        }

        try {
            StackRequest::fromArray(['action' => 'up', 'services' => ['web' => 'nginx']]);
            self::fail('a non-object service must be refused');
        } catch (InvalidStackRequest $e) {
            self::assertStringContainsString('service "web" must be an object', $e->getMessage());
        }
    }

    /**
     * The grammar facts, each pinned because the daemon's own rules are what
     * they copy: image references follow Docker's grammar (and digests are a
     * stated deferral), service names are DNS labels because that is what
     * they become on the session network.
     */
    /**
     * @param array<string, mixed> $service
     */
    #[DataProvider('refusedServices')]
    public function test_a_service_that_cannot_be_expressed_is_refused(string $name, array $service, string $expected): void
    {
        try {
            StackRequest::fromArray(['action' => 'up', 'services' => [$name => $service]]);
            self::fail('this service must be refused: '.$expected);
        } catch (InvalidStackRequest $e) {
            self::assertStringContainsString($expected, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function refusedServices(): iterable
    {
        yield 'uppercase service name' => ['Web', ['image' => 'nginx'], 'service names must match'];
        yield 'service name with a dot' => ['web.api', ['image' => 'nginx'], 'service names must match'];
        yield 'trailing newline in the name, which `$` would have accepted' => ["web\n", ['image' => 'nginx'], 'service names must match'];
        yield 'missing image' => ['web', [], 'image is required'];
        yield 'uppercase image name' => ['web', ['image' => 'Nginx'], 'image reference'];
        yield 'image digest (deferred, named)' => ['web', ['image' => 'nginx@sha256:abc'], 'digests are not supported'];
        yield 'image with a trailing slash' => ['web', ['image' => 'nginx/'], 'image reference'];
        yield 'command as a shell string' => ['web', ['image' => 'nginx', 'command' => 'php -S'], 'argv array, never a shell string'];
        yield 'mount without a volume' => ['web', ['image' => 'nginx', 'mounts' => [['target' => '/data']]], 'volume is required'];
        yield 'mount with a host path as the volume' => ['web', ['image' => 'nginx', 'mounts' => [['volume' => '/host', 'target' => '/data']]], 'volume names must match'];
        yield 'mount source that is the socket path' => ['web', ['image' => 'nginx', 'mounts' => [['volume' => '/var/run/docker.sock', 'target' => '/sock']]], 'volume names must match'];
        yield 'mount target with ..' => ['web', ['image' => 'nginx', 'mounts' => [['volume' => 'data', 'target' => '/data/../..']]], 'must not contain ".."'];
        yield 'depends_on an unknown shape' => ['web', ['image' => 'nginx', 'depends_on' => 42], 'depends_on'];
        yield 'depends_on with an unknown condition' => ['web', ['image' => 'nginx', 'depends_on' => ['db' => ['condition' => 'service_healthy']]], 'condition must be one of'];
        yield 'env with a reserved key' => ['web', ['image' => 'nginx', 'environment' => ['WW_SESSION' => 'x']], 'reserved for the broker'];
        yield 'env with a broker-owned key' => ['web', ['image' => 'nginx', 'environment' => ['PAGER' => 'less']], 'owned by the broker'];
        yield 'health_cmd as a shell string' => ['web', ['image' => 'nginx', 'health_cmd' => 'curl -f localhost'], 'argv array, never a shell string'];
    }

    public function test_a_service_that_uses_every_supported_field_is_accepted(): void
    {
        $request = StackRequest::fromArray([
            'action' => 'up',
            'services' => [
                'web' => [
                    'image' => 'ghcr.io/digitaladapt/taskloom:dev',
                    'command' => ['frankenphp', 'run', '--config', '/etc/frankenphp/Caddyfile'],
                    'environment' => ['APP_ENV' => 'test', 'DATABASE_URL' => 'sqlite:///%kernel.project_dir%/data/db.sqlite'],
                    'mounts' => [['volume' => 'app-data', 'target' => '/app/data', 'read_only' => false]],
                    'depends_on' => ['db' => ['condition' => 'healthy']],
                    'health_cmd' => ['curl', '-f', 'http://localhost/health'],
                ],
                'db' => [
                    'image' => 'postgres:17-alpine',
                    'environment' => ['POSTGRES_PASSWORD' => 'test'],
                    'mounts' => [['volume' => 'pgdata', 'target' => '/var/lib/postgresql/data']],
                    'health_cmd' => ['pg_isready', '-U', 'postgres'],
                ],
            ],
        ]);

        $web = $request->services['web'];
        self::assertSame(['frankenphp', 'run', '--config', '/etc/frankenphp/Caddyfile'], $web->command);
        self::assertSame('app-data', $web->mounts[0]->source->value);
        self::assertSame('/app/data', $web->mounts[0]->target->value);
        self::assertFalse($web->mounts[0]->readOnly);
        self::assertSame('db', $web->dependsOn[0][0]);
        self::assertSame(ServiceCondition::Healthy, $web->dependsOn[0][1]);
        self::assertSame(['curl', '-f', 'http://localhost/health'], $web->healthCmd);
    }

    public function test_the_short_depends_on_form_means_started(): void
    {
        $request = StackRequest::fromArray([
            'action' => 'up',
            'services' => [
                'web' => ['image' => 'nginx', 'depends_on' => ['db']],
                'db' => ['image' => 'postgres:17-alpine'],
            ],
        ]);

        self::assertSame('db', $request->services['web']->dependsOn[0][0]);
        self::assertSame(ServiceCondition::Started, $request->services['web']->dependsOn[0][1]);
    }

    public function test_too_many_services_are_refused(): void
    {
        $services = [];

        for ($i = 0; $i <= StackService::MAX_SERVICES; ++$i) {
            $services['svc'.$i] = ['image' => 'nginx'];
        }

        $this->expectException(InvalidStackRequest::class);
        StackRequest::fromArray(['action' => 'up', 'services' => $services]);
    }

    public function test_timeout_keeps_the_same_bounds_as_everything_else(): void
    {
        $request = StackRequest::fromArray(['action' => 'down', 'timeout' => '900']);
        self::assertSame(900, $request->timeout);

        foreach (['0', '-1', (string) (StackRequest::MAX_TIMEOUT + 1), '1.5'] as $bad) {
            try {
                StackRequest::fromArray(['action' => 'down', 'timeout' => $bad]);
                self::fail('a bad timeout must be refused: '.$bad);
            } catch (InvalidStackRequest) {
                // The point.
            }
        }
    }
}
