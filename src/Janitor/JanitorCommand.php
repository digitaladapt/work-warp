<?php

declare(strict_types=1);

namespace App\Janitor;

use App\Docker\DockerException;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `ww:janitor` — the sweep, as a process.
 *
 * Two shapes, one engine. Without `--every`, it sweeps once and exits, which
 * is the shape a cron entry or a CI step wants (and gives `ww-run reap`
 * something to become on the far side of DOCKER-ACCESS.md §9). With `--every`,
 * it loops — which is the shape the broker image's entrypoint uses, because
 * the design asks for "no cron and no second service" (§6a) and a child of
 * the process that is already long-lived is exactly that.
 *
 * A sweep that finds nothing is a success, not a failure: the broom is
 * allowed to have nothing to do. A sweep that *could not run* — the daemon
 * unreachable or refusing — is a failure, and so is a sweep where an action
 * on some container did not take: a cleanup that reports success while a
 * container it tried to stop is still running would be lying, and this
 * command's exit code is what a scheduler will read. Both distinctions are
 * asserted in the tests.
 *
 * SIGTERM and SIGINT stop the loop between sweeps rather than mid-sweep: a
 * half-done sweep is not a concept this class has, because each container's
 * action is a single Docker call.
 */
#[AsCommand(
    name: 'ww:janitor',
    description: 'Stop and remove work-warp containers past their ww.ttl',
)]
final class JanitorCommand extends Command implements SignalableCommandInterface
{
    /**
     * A floor under `--every`, so the loop cannot become a busy meter against
     * the daemon. Sixty is the shipped default in the entrypoint; five is the
     * fastest a human may ask for deliberately.
     */
    private const int MINIMUM_INTERVAL_SECONDS = 5;

    private bool $running = true;

    public function __construct(
        private readonly Janitor $janitor,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption(
            'every',
            null,
            InputOption::VALUE_REQUIRED,
            \sprintf('Seconds between sweeps (at least %d). Omit to sweep once and exit.', self::MINIMUM_INTERVAL_SECONDS),
        );
    }

    /**
     * @return list<int>
     */
    #[Override]
    public function getSubscribedSignals(): array
    {
        return [\SIGTERM, \SIGINT];
    }

    #[Override]
    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        // `false` continues execution: the flag is read between sweeps, so a
        // stop request during a sleep is honoured when the sleep ends, and a
        // stop request during a sweep when the sweep does. Returning an exit
        // code here would interrupt a sweep mid-call to no benefit.
        $this->running = false;

        return false;
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $raw = $input->getOption('every');

        if (null === $raw) {
            return $this->sweepOnce($io);
        }

        $every = $this->intervalFrom($raw, $io);

        if (null === $every) {
            return Command::INVALID;
        }

        $io->note(\sprintf('sweeping every %d seconds; stop with SIGTERM or Ctrl-C', $every));

        while ($this->running) {
            $this->sweepInLoop($io);

            if ($this->running) {
                $this->clock->sleep($every);
            }
        }

        $io->note('janitor stopped');

        return Command::SUCCESS;
    }

    /**
     * The interval `--every` asked for, or null (after saying why not) when it
     * asked for something this command will not do.
     */
    private function intervalFrom(mixed $raw, SymfonyStyle $io): ?int
    {
        if (!\is_string($raw) || 1 !== preg_match('/^\d+\z/', $raw) || (int) $raw < self::MINIMUM_INTERVAL_SECONDS) {
            $io->error(\sprintf(
                '--every must be a whole number of seconds, at least %d; got "%s"',
                self::MINIMUM_INTERVAL_SECONDS,
                \is_scalar($raw) ? (string) $raw : get_debug_type($raw),
            ));

            return null;
        }

        return (int) $raw;
    }

    /**
     * The one-shot shape: the report is the output, and the exit code says
     * whether anyone needs to look.
     */
    private function sweepOnce(SymfonyStyle $io): int
    {
        try {
            $report = $this->janitor->sweep();
        } catch (DockerException $e) {
            $io->error('the sweep could not run: '.$e->getMessage());

            return Command::FAILURE;
        }

        $this->render($io, $report);

        return [] === $report->failed ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * The loop shape: nothing exits on a bad sweep, because the next one may
     * be fine and the loop's whole value is being there later.
     */
    private function sweepInLoop(SymfonyStyle $io): void
    {
        try {
            $report = $this->janitor->sweep();
        } catch (DockerException $e) {
            $this->logger->warning('janitor: sweep skipped: {message}', ['message' => $e->getMessage()]);
            $io->warning('sweep skipped: '.$e->getMessage());

            return;
        }

        $io->writeln(\sprintf(
            '[%s] inspected %d, reaped %d, failed %d',
            $this->clock->now()->format('Y-m-d\TH:i:s\Z'),
            $report->inspected,
            \count($report->reaped),
            \count($report->failed),
        ));
    }

    private function render(SymfonyStyle $io, ReapReport $report): void
    {
        $io->writeln(\sprintf(
            'inspected %d container(s): %d reaped, %d failed',
            $report->inspected,
            \count($report->reaped),
            \count($report->failed),
        ));

        if ([] !== $report->reaped) {
            $rows = [];

            foreach ($report->reaped as $reaped) {
                $rows[] = [
                    $reaped->container,
                    $reaped->session ?? '—',
                    $reaped->action->value,
                    (string) $reaped->ageSeconds,
                    (string) $reaped->ttlSeconds,
                ];
            }

            $io->table(['container', 'session', 'action', 'age (s)', 'ttl (s)'], $rows);
        }

        foreach ($report->failed as $failure) {
            $io->warning(\sprintf('%s: %s', $failure->container, $failure->reason));
        }

        if ([] === $report->reaped && [] === $report->failed) {
            $io->success('nothing to do');
        }
    }
}
