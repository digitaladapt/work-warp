<?php

declare(strict_types=1);

namespace App\Janitor;

use App\Docker\DockerException;
use App\Exec\ExecRequest;
use App\Exec\ExecRunner;
use App\Exec\SlotUnavailable;
use App\Session\InvalidSessionName;
use App\Session\NoSuchSession;
use App\Session\SessionName;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * The janitor's line in a session's ledger — §8's "kills are written to the
 * ledger", implemented over the machinery that already exists.
 *
 * The ledger lives *inside the workspace volume* (PLAN.md §6.2), and this
 * process has no mount of it — the broker is not the thing that runs
 * commands, that is `ExecRunner`'s job. So the line is written the same way a
 * session writes one: by running a tiny disposable container with the
 * workspace mounted, through the normal exec lifecycle, with the same
 * constraints, caps and image as any other command. There is no second write
 * path and no privileged exception for "just the janitor"; the sweep gets the
 * same tool the sweep's subjects get.
 *
 * Best-effort, deliberately and loudly: a ledger line that cannot be written
 * must never turn a stopped container into a reason to leave it running.
 * Every failure is a log line, and none of them propagate.
 *
 * The clock is injected for the same reason it is everywhere else: the file
 * name and the line's timestamp come from *this* process's clock, not from a
 * `date` inside the container, so a test can pin them. (That also removes a
 * dependency on the command image shipping `date` in a particular form.)
 */
final class LedgerWriter
{
    /**
     * The directory's name inside the workspace, matching the convention
     * PLAN.md §6.2 established: one file per day, append-only.
     */
    public const string DIRECTORY = 'ledger';

    public function __construct(
        private readonly ExecRunner $runner,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {
    }

    public function recordReap(string $container, ?string $session, ReapAction $action, int $age, int $ttl): void
    {
        // A container with no `ww.session` has no workspace to write into;
        // the log line already carries the same facts, so there is nothing
        // here to approximate.
        if (null === $session) {
            return;
        }

        try {
            $name = SessionName::from($session);
        } catch (InvalidSessionName) {
            $this->logger->warning('janitor: cannot write a ledger line for {container}: "{session}" is not a session name', [
                'container' => $container,
                'session' => $session,
            ]);

            return;
        }

        $now = $this->clock->now();
        $file = $now->format('Y-m-d').'.md';

        $line = \sprintf(
            '%s — janitor: %s container %s (age %ds, ttl %ds)',
            $now->format('Y-m-d\TH:i:s\Z'),
            $action->value,
            $container,
            $age,
            $ttl,
        );

        // The command is an argv list and nothing here is shell-parsed: the
        // line is passed as a positional parameter, so no character of it can
        // be reinterpreted as syntax. The file name and directory are
        // likewise parameters, not interpolated into the script.
        $request = ExecRequest::fromArray([
            'cmd' => [
                '/bin/sh',
                '-c',
                'mkdir -p "$1" && printf \'%s\n\' "$3" >> "$1/$2"',
                'ww-janitor',
                self::DIRECTORY,
                $file,
                $line,
            ],
            'timeout' => 30,
        ]);

        try {
            $this->runner->run($name, $request);
        } catch (NoSuchSession) {
            // The session's workspace is gone. Nothing to write into, and
            // nothing wrong: the container being reaped may well have been
            // the last user of a workspace that has since been deleted.
            $this->logger->info('janitor: no workspace for session {session}; ledger line not written', [
                'session' => $session,
            ]);
        } catch (SlotUnavailable $e) {
            // Every slot is busy with real work. A ledger line is not worth
            // queueing or waiting for; the log has the same facts.
            $this->logger->info('janitor: no exec slot to write a ledger line for {session}: {message}', [
                'session' => $session,
                'message' => $e->getMessage(),
            ]);
        } catch (DockerException $e) {
            $this->logger->warning('janitor: could not write a ledger line for {session}: {message}', [
                'session' => $session,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
