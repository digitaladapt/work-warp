<?php

declare(strict_types=1);

namespace App\Tests\Unit\Janitor;

use App\Docker\DockerRefused;
use App\Docker\DockerUnavailable;
use App\Exec\ExecRunner;
use App\Exec\Slots;
use App\Janitor\Janitor;
use App\Janitor\JanitorCommand;
use App\Janitor\LedgerWriter;
use App\Tests\Support\FakeClock;
use App\Tests\Support\ScriptedDockerApi;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * `ww:janitor`: the one-shot shape's exit codes and output, and the guard on
 * the loop's interval.
 *
 * The exit code is the contract a scheduler reads, so it carries the
 * distinctions the design cares about: nothing to do is success, a sweep that
 * could not run is failure, and a sweep where an action did not take is
 * failure too — a cleanup that reported success while a container it tried to
 * stop was still running would be lying.
 *
 * @internal
 */
final class JanitorCommandTest extends TestCase
{
    private const int NOW = 1_767_225_600;

    public function test_a_one_shot_sweep_that_finds_nothing_reports_success(): void
    {
        [$docker, $tester] = $this->mount();
        $docker->seedContainer('id-young', 'ww-demo-abc123', true, self::NOW, $this->labels());

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('inspected 1 container(s): 0 reaped, 0 failed', $tester->getDisplay());
        self::assertStringContainsString('nothing to do', $tester->getDisplay());
    }

    public function test_a_one_shot_sweep_that_reaps_reports_success_and_a_table(): void
    {
        [$docker, $tester] = $this->mount();
        $docker->seedContainer('id-stopped', 'ww-demo-abc123', false, self::NOW - 400, $this->labels());

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();
        self::assertStringContainsString('1 reaped, 0 failed', $display);
        self::assertStringContainsString('ww-demo-abc123', $display);
        self::assertStringContainsString('removed', $display);
    }

    public function test_a_sweep_that_could_not_run_is_a_failure_with_the_reason(): void
    {
        [$docker, $tester] = $this->mount();
        $docker->failNext('listContainers', DockerUnavailable::because('the proxy is not answering'));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('the sweep could not run', $tester->getDisplay());
        self::assertStringContainsString('the proxy is not answering', $tester->getDisplay());
    }

    public function test_a_sweep_where_an_action_did_not_take_is_a_failure(): void
    {
        [$docker, $tester] = $this->mount();
        $docker->seedContainer('id-doomed', 'ww-demo-abc123', true, self::NOW - 400, $this->labels());
        $docker->failNext('stopContainer', DockerRefused::because('driver error'));

        self::assertSame(
            Command::FAILURE,
            $tester->execute([]),
            'reporting success while a container it tried to stop is still running would be lying',
        );
        self::assertStringContainsString('1 failed', $tester->getDisplay());
        self::assertStringContainsString('ww-demo-abc123: driver error', $tester->getDisplay());
    }

    public function test_the_loop_refuses_an_interval_below_the_floor(): void
    {
        [, $tester] = $this->mount();

        self::assertSame(Command::INVALID, $tester->execute(['--every' => '4']));
        self::assertStringContainsString('at least 5', $tester->getDisplay());
    }

    public function test_the_loop_refuses_an_interval_that_is_not_a_number(): void
    {
        [, $tester] = $this->mount();

        self::assertSame(Command::INVALID, $tester->execute(['--every' => 'often']));
        self::assertStringContainsString('whole number of seconds', $tester->getDisplay());
    }

    /**
     * @return array{ScriptedDockerApi, CommandTester}
     */
    private function mount(): array
    {
        $docker = new ScriptedDockerApi();
        $clock = new FakeClock();
        $slots = Slots::of(2, new InMemoryStore());

        $runner = new ExecRunner($docker, $slots, new NullLogger(), $clock, 'digitaladapt/work-warp:test-base', 300);
        $janitor = new Janitor($docker, new LedgerWriter($runner, new NullLogger(), $clock), new NullLogger(), $clock);
        $command = new JanitorCommand($janitor, new NullLogger(), $clock);

        return [$docker, new CommandTester($command)];
    }

    /**
     * @return array<string, string>
     */
    private function labels(string $ttl = '300'): array
    {
        return [
            'ww.created-by' => 'work-warp',
            'ww.session' => 'demo',
            'ww.kind' => 'cmd',
            'ww.ttl' => $ttl,
        ];
    }
}
