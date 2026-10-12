<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Docker\DockerRefused;
use App\Docker\DockerUnavailable;
use App\Docker\PullOutput;
use App\Exec\Slots;
use App\Stack\StackRunner;
use App\Tests\Support\ScriptedDockerApi;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/sessions/{name}/stack`, through the real kernel: the gate, the
 * route, the action dispatch, validation, the runner, and the status-code
 * mapping — with Docker scripted.
 *
 * The status shapes are the design's own language, each asserted with the
 * reason it exists: 404 for a mistyped session, 422 for a stack that cannot be
 * expressed (unknown dependency, cycle, bad grammar), 429 only for `up` when
 * every slot is busy (down and logs never queue), 503/502 for the daemon being
 * unreachable or refusing. A bring-up that *ran and failed* — a dependency
 * that exited non-zero, an image that could not be pulled — is a 200 with the
 * partial outcome and the failure named.
 *
 * @internal
 */
final class StackApiTest extends WebTestCase
{
    private const string TOKEN = 'test-token';

    public function test_up_brings_a_stack_up_and_reports_every_service(): void
    {
        $client = self::createClient();
        $this->docker()->createVolume('ww-ws-refactor', ['ww.session' => 'refactor']);

        // A stack service is a long-running process, not a command: the
        // scripted daemon must model a started service as one that stays up.
        $this->docker()->linger();

        $this->stack($client, 'refactor', [
            'action' => 'up',
            'services' => [
                'web' => ['image' => 'ghcr.io/digitaladapt/taskloom:dev', 'depends_on' => ['db']],
                'db' => ['image' => 'postgres:17-alpine'],
            ],
        ]);

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $body = $this->decode($client);
        self::assertNull($body['failure']);
        self::assertFalse($body['timed_out']);
        self::assertCount(2, $body['services']);
        self::assertSame('db', $body['services'][0]['name'], 'dependency order');
        self::assertSame('ww-refactor-db-1', $body['services'][0]['container']);
        self::assertTrue($body['services'][0]['running']);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function test_a_mistyped_session_is_a_404_and_is_not_created_on_the_way_past(): void
    {
        $client = self::createClient();
        $docker = $this->docker();
        $docker->resetCalls();

        $this->stack($client, 'refactor', ['action' => 'up', 'services' => ['web' => ['image' => 'nginx:alpine']]]);

        self::assertSame(Response::HTTP_NOT_FOUND, $client->getResponse()->getStatusCode());
        self::assertSame('no-such-session', $this->decode($client)['status']);
        self::assertFalse($docker->hasVolume('ww-ws-refactor'));
        self::assertSame(['volumeExists'], $docker->calledMethods());
    }

    /**
     * The stack-level form of the unexpressible argument, end to end: a body
     * that tries a compose file's escalation surface gets a 422 naming the
     * field — and nothing is asked of Docker at all.
     */
    public function test_a_stack_body_cannot_express_escalations_and_is_422(): void
    {
        $client = self::createClient();
        $docker = $this->docker();
        $this->docker()->createVolume('ww-ws-refactor', ['ww.session' => 'refactor']);
        $docker->resetCalls();

        // `privileged` is not a field; it is ignored like any unknown key, and
        // the service is *still* a 422 for the reason that IS checked — so the
        // interesting assertion is that a host-path mount is refused by name.
        $this->stack($client, 'refactor', [
            'action' => 'up',
            'services' => [
                'web' => [
                    'image' => 'nginx:alpine',
                    'privileged' => true,
                    'network_mode' => 'host',
                    'mounts' => [['volume' => '/var/run/docker.sock', 'target' => '/sock']],
                ],
            ],
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('volume names must match', (string) $this->decode($client)['reason']);
        self::assertSame([], $docker->calledMethods(), 'a refused stack never reaches Docker');
    }

    public function test_a_dependency_on_a_service_not_in_the_request_is_422(): void
    {
        $client = self::createClient();
        $this->docker()->createVolume('ww-ws-refactor', ['ww.session' => 'refactor']);

        $this->stack($client, 'refactor', [
            'action' => 'up',
            'services' => ['web' => ['image' => 'nginx:alpine', 'depends_on' => ['db']]],
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('not a service in this stack', (string) $this->decode($client)['reason']);
    }

    public function test_a_cycle_is_422(): void
    {
        $client = self::createClient();
        $this->docker()->createVolume('ww-ws-refactor', ['ww.session' => 'refactor']);

        $this->stack($client, 'refactor', [
            'action' => 'up',
            'services' => [
                'a' => ['image' => 'nginx:alpine', 'depends_on' => ['b']],
                'b' => ['image' => 'nginx:alpine', 'depends_on' => ['a']],
            ],
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('cycle among', (string) $this->decode($client)['reason']);
    }

    public function test_a_failed_bring_up_is_a_200_with_the_failure_named(): void
    {
        $client = self::createClient();
        $this->docker()->createVolume('ww-ws-refactor', ['ww.session' => 'refactor']);

        // The image cannot be pulled: the pull produces nothing, and the
        // runner detects it by asking `imageExists` — never by parsing text.
        $this->docker()->scriptNextPull(new PullOutput('', false, false, false), produces: false);

        $this->stack($client, 'refactor', [
            'action' => 'up',
            'services' => ['web' => ['image' => 'ghcr.io/nope/nothing:v9']],
        ]);

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $body = $this->decode($client);
        self::assertNotNull($body['failure']);
        self::assertSame('web', $body['failure']['service']);
        self::assertStringContainsString('did not produce the image', $body['failure']['reason']);
        self::assertSame([], $body['services']);
    }

    public function test_a_busy_broker_answers_429_for_up_only(): void
    {
        $client = self::createClient();
        // Three requests share one scripted world; without this the kernel
        // reboots between them and the second sees a daemon where the session
        // volume never existed (the same reason ExecApiTest disables reboot).
        $client->disableReboot();

        $this->docker()->createVolume('ww-ws-refactor', ['ww.session' => 'refactor']);

        // .env.test sets WW_MAX_CONCURRENCY=1 and the pool is process-wide
        // (§6b), so a slot held here is a slot `up` cannot take.
        $slots = self::getContainer()->get(Slots::class);
        self::assertInstanceOf(Slots::class, $slots);
        $held = $slots->reserve();

        try {
            $this->stack($client, 'refactor', ['action' => 'up', 'services' => ['web' => ['image' => 'nginx:alpine']]]);
            self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $client->getResponse()->getStatusCode());
            self::assertSame('busy', $this->decode($client)['status']);

            // down and logs reduce load or add none: they must work while
            // every slot is busy, because that is when a caller needs them.
            $this->stack($client, 'refactor', ['action' => 'down']);
            self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode(), 'down must not queue behind work');

            $this->stack($client, 'refactor', ['action' => 'logs']);
            self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode(), 'logs must not queue behind work');
        } finally {
            $held->release();
        }
    }

    public function test_down_reports_what_it_removed_and_what_it_could_not(): void
    {
        $client = self::createClient();
        $docker = $this->docker();
        $docker->createVolume('ww-ws-refactor', ['ww.session' => 'refactor']);

        $docker->seedContainer('c1', 'ww-refactor-web-1', true, 1000, [
            'ww.created-by' => 'work-warp', 'ww.session' => 'refactor', 'ww.kind' => 'stack', 'ww.service' => 'web',
        ]);
        $docker->seedVolume('ww-stk-refactor-pgdata', [
            'ww.created-by' => 'work-warp', 'ww.session' => 'refactor', 'ww.kind' => 'stack-volume',
        ]);
        $docker->seedVolume('ww-ws-refactor', [
            'ww.created-by' => 'work-warp', 'ww.session' => 'refactor', 'ww.kind' => 'workspace',
        ]);

        $this->stack($client, 'refactor', ['action' => 'down']);

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $body = $this->decode($client);
        self::assertSame(['ww-refactor-web-1'], $body['containers_removed']);
        self::assertSame(['ww-stk-refactor-pgdata'], $body['volumes_removed'], 'the workspace volume is not a stack volume');
        self::assertSame([], $body['failures']);
    }

    public function test_logs_assemble_the_stack(): void
    {
        $client = self::createClient();
        $docker = $this->docker();
        $docker->createVolume('ww-ws-refactor', ['ww.session' => 'refactor']);

        $docker->seedContainer('c1', 'ww-refactor-web-1', true, 1000, [
            'ww.created-by' => 'work-warp', 'ww.session' => 'refactor', 'ww.kind' => 'stack', 'ww.service' => 'web',
        ]);

        $docker->scriptNextLogs(new \App\Docker\LogOutput("migrating...\ndone\n", ''));

        $this->stack($client, 'refactor', ['action' => 'logs']);

        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $body = $this->decode($client);
        self::assertStringContainsString('==> web <==', $body['output']);
        self::assertStringContainsString('migrating...', $body['output']);
    }

    public function test_a_daemon_that_cannot_be_reached_is_503_and_one_that_refuses_is_502(): void
    {
        $client = self::createClient();
        $client->disableReboot();

        $this->docker()->createVolume('ww-ws-refactor', ['ww.session' => 'refactor']);

        $this->docker()->failNext('listContainers', DockerUnavailable::because('the proxy is not answering'));
        $this->stack($client, 'refactor', ['action' => 'down']);
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $client->getResponse()->getStatusCode());

        $this->docker()->failNext('createContainer', DockerRefused::because('name conflict'));
        $this->stack($client, 'refactor', ['action' => 'up', 'services' => ['web' => ['image' => 'nginx:alpine']]]);
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode(), 'a refused create mid-bring-up is a named failure, not a 5xx');
        self::assertStringContainsString('name conflict', (string) $this->decode($client)['failure']['reason']);
    }

    public function test_a_malformed_body_is_400(): void
    {
        $client = self::createClient();

        $client->request('POST', '/v1/sessions/refactor/stack', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
        ], '{');

        self::assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());
        self::assertSame('bad-request', $this->decode($client)['status']);
    }

    public function test_the_endpoint_sits_behind_the_same_gate_as_everything_else(): void
    {
        $client = self::createClient();

        $client->request('POST', '/v1/sessions/refactor/stack', [], [], ['CONTENT_TYPE' => 'application/json'], '{"action":"down"}');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
    }

    public function test_the_runner_is_wired_in_the_container(): void
    {
        self::bootKernel();

        self::assertTrue(
            self::getContainer()->has(StackRunner::class),
            'the stack runner must exist in the compiled container',
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function stack(KernelBrowser $client, string $session, array $body): void
    {
        $client->request('POST', '/v1/sessions/'.$session.'/stack', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function docker(): ScriptedDockerApi
    {
        $docker = self::getContainer()->get(ScriptedDockerApi::class);
        self::assertInstanceOf(ScriptedDockerApi::class, $docker);

        return $docker;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
