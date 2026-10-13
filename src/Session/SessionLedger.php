<?php

declare(strict_types=1);

namespace App\Session;

use App\Docker\DockerException;
use App\Exec\ExecRequest;
use App\Exec\ExecResult;
use App\Exec\ExecRunner;
use App\Exec\SlotUnavailable;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * What a session's ledger holds, in one quoted answer.
 *
 * The ledger lives *inside the workspace volume* (PLAN.md §6.2), so a process
 * with no mount of it reads it the same way the janitor writes to it: by
 * running a disposable container with the workspace mounted, with the same
 * constraints and the same image as any other command. There is no second
 * read path and no privileged exception for "just reading".
 *
 * Reading the *whole* ledger would grow without bound — a session that lasts
 * months writes a lot of lines — so this returns today's file plus the list of
 * files present. That is the useful shape for `GET /v1/sessions/{name}`: the
 * recent history inline, the rest by name for a caller that wants to `exec`
 * its way through them.
 *
 * The clock is injected for the same reason the janitor's writer injects it:
 * *today* — the file name being read — comes from this process's clock, not
 * from a `date` inside the container, so the two ends of the convention agree
 * by construction and a test can pin the day.
 *
 * Best-effort, deliberately: a read that cannot happen is `null` with a log
 * line, never a 5xx — the session exists and its containers are real whether
 * or not the ledger is legible, and the endpoint's job is to say what is true.
 */
final class SessionLedger
{
    /**
     * Inside the workspace, matching `LedgerWriter::DIRECTORY` — the two ends
     * of the same convention. Not imported from there on purpose: this class
     * must not depend on the janitor, and the value is part of the workspace's
     * layout rather than of any one writer.
     */
    public const string DIRECTORY = 'ledger';

    /**
     * The read is a command like any other: 30 seconds is generous for a
     * `tail`-shaped read of a text file, and the output cap is the exec
     * result's own.
     */
    private const int READ_TIMEOUT_SECONDS = 30;

    /**
     * How many day files are listed, newest first. Bounded so the answer stays
     * an answer; the content read is today's file only.
     */
    private const int MAX_DAYS_LISTED = 60;

    public function __construct(
        private readonly ExecRunner $runner,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array{days: list<string>, today: string|null}|null null when the read could not happen
     */
    public function read(SessionName $session): ?array
    {
        // Line-structured on purpose (`ledger::` section markers), so the
        // parse does not depend on the shell having `date` or `ls` in any
        // particular form. Every argument is a positional parameter — nothing
        // above is interpolated into a script, so no character of any name
        // can be reinterpreted as syntax.
        $script = <<<'SH'
            set -e
            dir="$1"
            day="$3"
            [ -d "$dir" ] || { printf 'ledger::none\n'; exit 0; }
            printf 'ledger::days\n'
            ls -1 "$dir" 2>/dev/null | tail -n "$2" || true
            printf 'ledger::today\n'
            if [ -f "$dir/$day.md" ]; then cat "$dir/$day.md"; fi
            SH;

        $request = ExecRequest::fromArray([
            'cmd' => [
                '/bin/sh',
                '-c',
                $script,
                'ww-ledger-read',
                self::DIRECTORY,
                (string) self::MAX_DAYS_LISTED,
                $this->clock->now()->format('Y-m-d'),
            ],
            'timeout' => self::READ_TIMEOUT_SECONDS,
        ]);

        try {
            $result = $this->runner->run($session, $request);
        } catch (NoSuchSession $e) {
            // The workspace vanished between the caller's own existence check
            // and this read; nothing to read, and the caller has already
            // decided its answer.
            $this->logger->info('session ledger: no workspace to read for {session}: {message}', [
                'session' => $session->value,
                'message' => $e->getMessage(),
            ]);

            return null;
        } catch (SlotUnavailable $e) {
            // Every slot is busy with real work. A ledger read is not worth
            // queueing for; the rest of the answer is still true.
            $this->logger->info('session ledger: no exec slot to read the ledger of {session}: {message}', [
                'session' => $session->value,
                'message' => $e->getMessage(),
            ]);

            return null;
        } catch (DockerException $e) {
            $this->logger->warning('session ledger: could not read the ledger of {session}: {message}', [
                'session' => $session->value,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        return self::parse($result);
    }

    /**
     * @return array{days: list<string>, today: string|null}
     */
    private static function parse(ExecResult $result): array
    {
        $days = [];
        $today = '';
        $section = '';

        foreach (preg_split('/\R/', $result->stdout) ?: [] as $line) {
            if ('ledger::none' === $line) {
                return ['days' => [], 'today' => null];
            }

            if ('ledger::days' === $line) {
                $section = 'days';

                continue;
            }

            if ('ledger::today' === $line) {
                $section = 'today';

                continue;
            }

            if ('days' === $section && 1 === preg_match('/^\d{4}-\d{2}-\d{2}\.md\z/', $line)) {
                $days[] = $line;
            } elseif ('today' === $section) {
                $today .= $line."\n";
            }
        }

        return [
            // `ls` lists oldest-first (the names sort chronologically); the
            // answer reads newest-first, like every other list here.
            'days' => array_reverse($days),
            'today' => '' === trim($today) ? null : rtrim($today, "\n"),
        ];
    }
}
