<?php

declare(strict_types=1);

namespace App\Tests\Unit\Janitor;

use App\Docker\DockerRefused;
use App\Docker\Labels;
use App\Exec\ExecRunner;
use App\Exec\Slots;
use App\Janitor\Janitor;
use App\Janitor\LedgerWriter;
use App\Janitor\ReapAction;
use App\Tests\Support\FakeClock;
use App\Tests\Support\ScriptedDockerApi;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * The sweep's decisions, taken against a scripted daemon and a pinned clock.
 *
 * What is asserted is the whole of the design's table — running past its TTL
 * is stopped; not running past it is removed with its anonymous volumes —
 * plus the edges that make it safe to leave unattended: a container under its
 * TTL is untouched, one with no readable TTL is left alone rather than
 * guessed at, and one action that fails does not stop the rest of the sweep.
 *
 * @internal
 */
final class JanitorTest extends TestCase
{
    /**
     * The epoch FakeClock starts at — the sweep's "now" for every test here.
     */
    private const int NOW = 1_767_225_600;

    public function test_a_running_container_past_its_ttl_is_stopped_and_not_removed(): void
    {
        [$docker, $janitor] = $this->mount();
        $docker->seedContainer('id-running', 'ww-demo-abc123', true, self::NOW - 301, $this->labels(ttl: '300'));

        $report = $janitor->sweep();

        self::assertSame(1, $report->inspected);
        self::assertSame(
            ['listContainers', 'stopContainer', 'volumeExists'],
            $docker->calledMethods(),
            'a container this pass stopped is not also removed in the same pass: the next sweep finds it stopped and removes it. '
            .'The trailing volumeExists is the ledger attempt checking the workspace before writing — no workspace here, so it skips.',
        );
        self::assertSame([['id' => 'id-running']], $this->argsFor($docker, 'stopContainer'));

        self::assertCount(1, $report->reaped);
        $reaped = $report->reaped[0];
        self::assertSame('ww-demo-abc123', $reaped->container);
        self::assertSame('demo', $reaped->session);
        self::assertSame(ReapAction::Stopped, $reaped->action);
        self::assertSame(301, $reaped->ageSeconds);
        self::assertSame(300, $reaped->ttlSeconds);
    }

    public function test_a_stopped_container_past_its_ttl_is_removed_with_its_anonymous_volumes(): void
    {
        [$docker, $janitor] = $this->mount();
        $docker->seedContainer('id-stopped', 'ww-demo-def456', false, self::NOW - 5000, $this->labels(ttl: '300'));

        $report = $janitor->sweep();

        self::assertSame(['listContainers', 'removeContainer', 'volumeExists'], $docker->calledMethods());
        self::assertSame(
            [['id' => 'id-stopped', 'v' => true]],
            $this->argsFor($docker, 'removeContainer'),
            'v=1: anonymous volumes go with the container — PLAN.md §8 says they are what fills a disk',
        );

        self::assertCount(1, $report->reaped);
        self::assertSame(ReapAction::Removed, $report->reaped[0]->action);
    }

    public function test_a_container_under_its_ttl_is_untouched(): void
    {
        [$docker, $janitor] = $this->mount();
        $docker->seedContainer('id-young', 'ww-demo-abc123', true, self::NOW - 299, $this->labels(ttl: '300'));

        $report = $janitor->sweep();

        self::assertSame(1, $report->inspected);
        self::assertSame([], $report->reaped);
        self::assertSame([], $report->failed);
        self::assertSame(['listContainers'], $docker->calledMethods(), 'nothing under its TTL may be touched');
    }

    public function test_the_boundary_is_the_container_s_own_ttl_not_an_approximation_of_it(): void
    {
        // age == ttl exactly: this is the first second at which the sweep may
        // act, and it does. One second less, and it does not.
        [$docker, $janitor] = $this->mount();
        $docker->seedContainer('id-exact', 'ww-demo-abc123', true, self::NOW - 300, $this->labels(ttl: '300'));

        $report = $janitor->sweep();

        self::assertCount(1, $report->reaped);
        self::assertSame(300, $report->reaped[0]->ageSeconds);
    }

    public function test_a_container_with_no_readable_ttl_is_left_alone_not_guessed_at(): void
    {
        [$docker, $janitor] = $this->mount();

        // No label at all…
        $docker->seedContainer('id-bare', 'ww-demo-one', true, self::NOW - 999999, $this->labels(ttl: null));
        // …a label that is not a number…
        $docker->seedContainer('id-text', 'ww-demo-two', true, self::NOW - 999999, $this->labels(ttl: 'soon'));
        // …and one that is a number with a newline smuggled on the end, which
        // `$` in a naive pattern would accept (the trap ExecRequest records).
        $docker->seedContainer('id-newline', 'ww-demo-three', true, self::NOW - 999999, $this->labels(ttl: "300\n"));
        // …and zero, which would make every container instantly sweepable.
        $docker->seedContainer('id-zero', 'ww-demo-four', true, self::NOW - 999999, $this->labels(ttl: '0'));

        $report = $janitor->sweep();

        self::assertSame(4, $report->inspected);
        self::assertSame([], $report->reaped);
        self::assertSame([], $report->failed);
        self::assertSame(['listContainers'], $docker->calledMethods());
    }

    public function test_one_action_that_fails_does_not_stop_the_rest_of_the_sweep(): void
    {
        [$docker, $janitor] = $this->mount();
        $docker->seedContainer('id-doomed', 'ww-demo-one', true, self::NOW - 400, $this->labels(ttl: '300'));
        $docker->seedContainer('id-fine', 'ww-demo-two', false, self::NOW - 400, $this->labels(ttl: '300'));

        $docker->failNext('stopContainer', DockerRefused::because('driver error'));

        $report = $janitor->sweep();

        self::assertCount(1, $report->failed);
        self::assertSame('ww-demo-one', $report->failed[0]->container);
        self::assertStringContainsString('driver error', $report->failed[0]->reason);

        self::assertCount(1, $report->reaped);
        self::assertSame('ww-demo-two', $report->reaped[0]->container);
        self::assertContains('removeContainer', $docker->calledMethods(), 'the second container was still acted on');
    }

    public function test_a_reap_is_written_to_the_session_s_ledger_through_a_disposable_container(): void
    {
        [$docker, $janitor] = $this->mount();
        $docker->createVolume('ww-ws-demo', ['ww.session' => 'demo']);
        $docker->resetCalls();
        $docker->seedContainer('id-stopped', 'ww-demo-abc123', false, self::NOW - 400, $this->labels(ttl: '300'));
        $docker->runToCompletion(0);

        $janitor->sweep();

        $methods = $docker->calledMethods();
        self::assertContains('createContainer', $methods, 'the ledger line goes through the normal exec path');

        // The ledger command, as built: a shell, the write script, and the
        // line passed as a *parameter* — never interpolated into the script.
        $create = null;

        foreach ($docker->calls() as $call) {
            if ('createContainer' === $call['method']) {
                $create = $call;
            }
        }

        self::assertNotNull($create);

        /** @var array<string, mixed> $payload */
        $payload = $create['args']['payload'];
        /** @var list<string> $cmd */
        $cmd = $payload['Cmd'];

        self::assertSame('/bin/sh', $cmd[0]);
        self::assertStringContainsString('>> "$1/$2"', $cmd[2]);
        self::assertSame('ledger', $cmd[4], 'the directory inside the workspace, per PLAN.md §6.2');
        self::assertSame('2026-01-01.md', $cmd[5], 'the clock\'s date, not the container\'s');
        self::assertStringContainsString('removed container ww-demo-abc123', $cmd[6]);
        self::assertStringContainsString('age 400s, ttl 300s', $cmd[6]);
    }

    public function test_a_container_with_no_session_is_reaped_without_a_ledger_attempt(): void
    {
        [$docker, $janitor] = $this->mount();
        $docker->seedContainer('id-orphan', 'ww-stray-abc123', false, self::NOW - 400, $this->labels(ttl: '300', session: null));

        $janitor->sweep();

        self::assertSame(
            ['listContainers', 'removeContainer'],
            $docker->calledMethods(),
            'no ww.session means no workspace to write into; the log line carries the same facts',
        );
    }

    public function test_an_unknown_session_label_is_reported_and_skips_only_the_ledger_line(): void
    {
        [$docker, $janitor] = $this->mount();
        $docker->seedContainer('id-weird', 'ww-odd-abc123', false, self::NOW - 400, $this->labels(ttl: '300', session: 'Not A Session!'));

        $report = $janitor->sweep();

        self::assertCount(1, $report->reaped, 'the container is still reaped');
        self::assertSame(['listContainers', 'removeContainer'], $docker->calledMethods(), 'but no ledger attempt is made for an unrepresentable session');
    }

    public function test_the_report_serialises_to_the_documented_shape(): void
    {
        [$docker, $janitor] = $this->mount();
        $docker->seedContainer('id-stopped', 'ww-demo-abc123', false, self::NOW - 400, $this->labels(ttl: '300'));

        $report = $janitor->sweep();

        self::assertSame([
            'inspected' => 1,
            'reaped' => [[
                'container' => 'ww-demo-abc123',
                'session' => 'demo',
                'action' => 'removed',
                'age_seconds' => 400,
                'ttl_seconds' => 300,
            ]],
            'failed' => [],
        ], $report->toArray());
    }

    /**
     * @return array{ScriptedDockerApi, Janitor}
     */
    private function mount(): array
    {
        $docker = new ScriptedDockerApi();
        $clock = new FakeClock();
        $slots = Slots::of(2, new InMemoryStore());

        $runner = new ExecRunner($docker, $slots, new NullLogger(), $clock, 'digitaladapt/work-warp:test-base', 300);
        $ledger = new LedgerWriter($runner, new NullLogger(), $clock);
        $janitor = new Janitor($docker, $ledger, new NullLogger(), $clock);

        self::assertInstanceOf(ClockInterface::class, $clock);

        return [$docker, $janitor];
    }

    /**
     * @return array<string, string>
     */
    private function labels(?string $ttl, ?string $session = 'demo'): array
    {
        $labels = [
            Labels::LABEL_CREATED_BY => Labels::CREATED_BY,
            Labels::LABEL_KIND => Labels::KIND_CMD,
        ];

        if (null !== $ttl) {
            $labels[Labels::LABEL_TTL] = $ttl;
        }

        if (null !== $session) {
            $labels[Labels::LABEL_SESSION] = $session;
        }

        return $labels;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function argsFor(ScriptedDockerApi $docker, string $method): array
    {
        $args = [];

        foreach ($docker->calls() as $call) {
            if ($call['method'] === $method) {
                $args[] = $call['args'];
            }
        }

        return $args;
    }
}
