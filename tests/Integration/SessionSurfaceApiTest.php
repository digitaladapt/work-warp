<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Docker\DockerRefused;
use App\Docker\DockerUnavailable;
use App\Docker\LogOutput;
use App\Tests\Support\ScriptedDockerApi;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The session surface beyond the create: list, show, ps, and delete — through
 * the real kernel, the real gate and the real routes, with Docker scripted.
 *
 * The properties worth holding, endpoint by endpoint:
 *
 * - **List discovers sessions from the label, not the name.** A stack volume
 *   and a neighbour's volume are in the same listing; neither is a session.
 * - **Show tells the truth about the ledger**: today's content and the days
 *   present when the read works, `null` when it cannot — never a 5xx, because
 *   the containers are real whether or not a convenience read is legible.
 * - **ps is the containers alone**, in the compact shape, still label-filtered.
 * - **Delete reports precisely**: what went, what was kept, what refused —
 *   and a missing session is a `404`, not a triumphant no-op.
 *
 * @internal
 */
final class SessionSurfaceApiTest extends WebTestCase
{
    private const string TOKEN = 'test-token';

    public function test_listing_finds_sessions_by_label_and_leaves_everything_else_out(): void
    {
        $client = self::createClient();
        $docker = $this->docker();

        $docker->seedVolume('ww-ws-refactor', $this->workspaceLabels('refactor'));
        $docker->seedVolume('ww-ws-demo', $this->workspaceLabels('demo'));
        // Not a session: a stack's data volume, and a volume with no labels.
        $docker->seedVolume('ww-stk-refactor-pgdata', ['ww.created-by' => 'work-warp', 'ww.session' => 'refactor', 'ww.kind' => 'stack-volume']);
        $docker->seedVolume('some-other-volume', []);

        $this->get($client, '/v1/sessions');

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame(
            [
                ['session' => 'demo', 'workspace' => 'ww-ws-demo'],
                ['session' => 'refactor', 'workspace' => 'ww-ws-refactor'],
            ],
            $this->decode($client),
            'sorted, and discovered from the workspace label rather than the name',
        );
    }

    public function test_showing_a_session_returns_its_workspace_containers_and_ledger(): void
    {
        $client = self::createClient();
        $docker = $this->docker();

        $docker->seedVolume('ww-ws-refactor', $this->workspaceLabels('refactor'));
        $docker->seedContainer('c1', 'ww-refactor-abc123', false, 1_767_225_200, $this->commandLabels('refactor'));
        $docker->seedContainer('c2', 'ww-refactor-web-1', true, 1_767_225_600, $this->stackLabels('refactor'));
        $docker->seedContainer('c3', 'ww-other-abc123', true, 1_767_225_600, $this->commandLabels('other'));

        // The ledger read is a real exec through the scripted daemon: the
        // output is the line-structured answer the shell script produces.
        $docker->scriptNextLogs(new LogOutput("ledger::days\n2026-10-12.md\nledger::today\n- wrote the first line\n"));

        $this->get($client, '/v1/sessions/refactor');

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $body = $this->decode($client);

        self::assertSame('refactor', $body['session']);
        self::assertSame('ww-ws-refactor', $body['workspace']);
        self::assertSame(
            [
                ['name' => 'ww-refactor-abc123', 'id' => 'c1', 'kind' => 'cmd', 'running' => false, 'service' => null, 'created_at' => 1_767_225_200],
                ['name' => 'ww-refactor-web-1', 'id' => 'c2', 'kind' => 'stack', 'running' => true, 'service' => 'web', 'created_at' => 1_767_225_600],
            ],
            $body['containers'],
            'label-filtered, sorted by name, and the neighbour is absent',
        );
        self::assertSame(['2026-10-12.md'], $body['ledger']['days']);
        self::assertSame('- wrote the first line', $body['ledger']['today']);
    }

    public function test_the_ledger_is_null_when_it_cannot_be_read_and_the_view_is_still_true(): void
    {
        $client = self::createClient();
        $docker = $this->docker();

        $docker->seedVolume('ww-ws-refactor', $this->workspaceLabels('refactor'));
        $docker->seedContainer('c1', 'ww-refactor-abc123', true, 1_767_225_600, $this->commandLabels('refactor'));

        // The ledger read is a disposable container, so this is where its
        // failure surfaces: the read dies, and the view must not.
        $docker->failNext('createContainer', DockerRefused::because('No such image: digitaladapt/work-warp:test-base'));

        $this->get($client, '/v1/sessions/refactor');

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $body = $this->decode($client);

        self::assertNull($body['ledger'], 'a read that cannot happen is null, never a 5xx');
        self::assertCount(1, $body['containers'], 'and the rest of the answer is still true');
    }

    public function test_ps_returns_the_containers_alone(): void
    {
        $client = self::createClient();
        $docker = $this->docker();

        $docker->seedVolume('ww-ws-refactor', $this->workspaceLabels('refactor'));
        $docker->seedContainer('c1', 'ww-refactor-web-1', true, 1_767_225_600, $this->stackLabels('refactor'));

        $this->get($client, '/v1/sessions/refactor/ps');

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertSame(
            [['name' => 'ww-refactor-web-1', 'id' => 'c1', 'kind' => 'stack', 'running' => true, 'service' => 'web', 'created_at' => 1_767_225_600]],
            $this->decode($client),
        );
    }

    public function test_deleting_a_session_removes_it_and_reports_the_deletion(): void
    {
        $client = self::createClient();
        $docker = $this->docker();

        $docker->createVolume('ww-ws-refactor', $this->workspaceLabels('refactor'));
        $docker->createNetwork('ww-net-refactor', ['ww.created-by' => 'work-warp', 'ww.session' => 'refactor', 'ww.kind' => 'network']);
        $docker->seedContainer('c1', 'ww-refactor-abc123', false, 1_767_225_200, $this->commandLabels('refactor'));
        $docker->seedVolume('ww-stk-refactor-pgdata', ['ww.created-by' => 'work-warp', 'ww.session' => 'refactor', 'ww.kind' => 'stack-volume']);

        $this->delete($client, '/v1/sessions/refactor');

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        $body = $this->decode($client);

        self::assertSame(['ww-refactor-abc123'], $body['containers_removed']);
        self::assertSame(['ww-net-refactor'], $body['networks_removed']);
        self::assertSame(['ww-stk-refactor-pgdata', 'ww-ws-refactor'], $body['volumes_removed']);
        self::assertSame([], $body['failures']);
        self::assertTrue($body['workspace_removed']);

        self::assertFalse($docker->hasVolume('ww-ws-refactor'));
        self::assertFalse($docker->hasNetwork('ww-net-refactor'));
    }

    public function test_deleting_with_keep_workspace_keeps_every_volume(): void
    {
        $client = self::createClient();
        $docker = $this->docker();

        $docker->createVolume('ww-ws-refactor', $this->workspaceLabels('refactor'));
        $docker->seedVolume('ww-stk-refactor-pgdata', ['ww.created-by' => 'work-warp', 'ww.session' => 'refactor', 'ww.kind' => 'stack-volume']);

        $this->delete($client, '/v1/sessions/refactor', ['keep_workspace' => true]);

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $body = $this->decode($client);

        self::assertSame([], $body['volumes_removed']);
        self::assertFalse($body['workspace_removed']);
        self::assertTrue($docker->hasVolume('ww-ws-refactor'));
        self::assertTrue($docker->hasVolume('ww-stk-refactor-pgdata'));
    }

    public function test_deleting_a_session_that_is_not_there_is_a_404_not_a_triumph(): void
    {
        $client = self::createClient();

        $this->delete($client, '/v1/sessions/refactor');

        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
        self::assertSame('no-such-session', $this->decode($client)['status']);
    }

    #[DataProvider('missingSessionPaths')]
    public function test_reading_a_session_that_is_not_there_is_a_404(string $path): void
    {
        $client = self::createClient();

        $this->get($client, $path);

        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
        self::assertSame('no-such-session', $this->decode($client)['status']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function missingSessionPaths(): iterable
    {
        yield 'show' => ['/v1/sessions/refactor'];
        yield 'ps' => ['/v1/sessions/refactor/ps'];
    }

    #[DataProvider('unpresentableNames')]
    public function test_an_unpresentable_name_is_refused_before_docker_is_asked(string $method, string $path): void
    {
        $client = self::createClient();
        $docker = $this->docker();
        $docker->resetCalls();

        $this->request($client, $method, $path);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        self::assertSame('invalid', $this->decode($client)['status']);
        self::assertSame([], $docker->calledMethods(), 'an invalid name must be refused before Docker is asked anything');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unpresentableNames(): iterable
    {
        yield 'show: uppercase' => ['GET', '/v1/sessions/Refactor'];
        yield 'show: newline' => ['GET', '/v1/sessions/refactor%0A'];
        yield 'ps: underscore' => ['GET', '/v1/sessions/refactor_this/ps'];
        yield 'delete: space' => ['DELETE', '/v1/sessions/refactor%20this'];
    }

    /**
     * An encoded slash is refused one layer earlier, and that is worth
     * pinning rather than surprised by: the router decodes the segment, sees
     * a path it has no route for, and answers `404` before any controller
     * exists to validate anything. Refused is refused — what matters is that
     * nothing reached Docker either way.
     */
    public function test_a_path_the_router_cannot_match_touches_nothing(): void
    {
        $client = self::createClient();
        $docker = $this->docker();
        $docker->resetCalls();

        $this->get($client, '/v1/sessions/..%2Frefactor/ps');

        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
        self::assertSame([], $docker->calledMethods());
    }

    public function test_a_daemon_that_cannot_be_reached_is_503(): void
    {
        $client = self::createClient();
        $this->docker()->failNext('listVolumes', DockerUnavailable::because('the proxy is not answering'));

        $this->get($client, '/v1/sessions');

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $client->getResponse()->getStatusCode());
        self::assertSame('unavailable', $this->decode($client)['status']);
    }

    public function test_a_keep_workspace_that_is_not_a_boolean_is_refused(): void
    {
        $client = self::createClient();
        $docker = $this->docker();
        $docker->createVolume('ww-ws-refactor', $this->workspaceLabels('refactor'));

        $this->delete($client, '/v1/sessions/refactor', ['keep_workspace' => 'yes']);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        self::assertSame('invalid', $this->decode($client)['status']);
        self::assertTrue($docker->hasVolume('ww-ws-refactor'), 'nothing is removed when the request cannot be understood');
    }

    private function get(KernelBrowser $client, string $path): void
    {
        $this->request($client, 'GET', $path);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function delete(KernelBrowser $client, string $path, array $body = []): void
    {
        $client->request('DELETE', $path, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
        ], [] === $body ? '' : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function request(KernelBrowser $client, string $method, string $path): void
    {
        $client->request($method, $path, [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(KernelBrowser $client): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function docker(): ScriptedDockerApi
    {
        $docker = self::getContainer()->get(ScriptedDockerApi::class);
        self::assertInstanceOf(ScriptedDockerApi::class, $docker);

        return $docker;
    }

    /**
     * @return array<string, string>
     */
    private function workspaceLabels(string $session): array
    {
        return ['ww.created-by' => 'work-warp', 'ww.session' => $session, 'ww.kind' => 'workspace'];
    }

    /**
     * @return array<string, string>
     */
    private function commandLabels(string $session): array
    {
        return ['ww.created-by' => 'work-warp', 'ww.session' => $session, 'ww.kind' => 'cmd', 'ww.ttl' => '2100'];
    }

    /**
     * @return array<string, string>
     */
    private function stackLabels(string $session): array
    {
        return ['ww.created-by' => 'work-warp', 'ww.session' => $session, 'ww.kind' => 'stack', 'ww.service' => 'web', 'ww.ttl' => '2100'];
    }
}
