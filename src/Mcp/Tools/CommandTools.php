<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Exec\ExecRequest;
use App\Exec\ExecResult;
use App\Exec\ExecRunner;
use App\Exec\NetworkMode;
use App\Exec\Workdir;
use App\Session\SessionName;
use App\Support\Utf8;

/**
 * `run_command` and `quick_command` — the command-container lifecycle, offered
 * under the two names a model can tell apart.
 *
 * One ephemeral container per command: the session's workspace volume at
 * `/workspace` and nothing else mounted, `--init`, `--cap-drop=ALL`, a
 * read-only rootfs, memory/CPU/pids caps, and a deadline the broker owns. The
 * workspace persists; the container does not. That is the whole product, and
 * it is the same `ExecRunner` the HTTP endpoint uses — there is no second way
 * to run a command in this codebase.
 *
 * **Why two tools and not one.** `cmd` is arbitrary by design, so the schema
 * cannot say what a good command is. What a model *can* act on is the
 * difference the names carry: `quick_command` defaults `cmd` to `true` and
 * bounds a run at a minute — the "is this workspace there, does a command
 * run" probe — while `run_command` requires `cmd` and allows half an hour.
 * **Neither is a privilege difference.** They build the same `ExecRequest` and
 * reach the same runner; the pair is naming, and saying so here is what stops
 * anyone reading a security boundary into it.
 *
 * **No `stdin`, deliberately.** The endpoint refuses it (`501`) because
 * delivering input needs the daemon's connection-hijacking attach path, which
 * this client does not implement. A tool that advertised a field it could
 * never honour would be lying to the model, so the field is absent and the
 * workaround belongs in the description: write the input to a file in the
 * workspace and read it from the command.
 *
 * **Exit codes are results, not errors.** A command that exits non-zero
 * returns `{"exit_code": 1, …}` — a success as far as the protocol is
 * concerned — because "it ran and failed" and "it could not run" are different
 * facts and the interface is built to keep them apart. Only a refusal that is
 * about the *request* (no such session, no free slot, an unrepresentable name)
 * becomes a tool error; those arrive as `ToolCallException`s through the shared
 * error translation, carrying the broker's own words.
 */
final class CommandTools
{
    /** The probe's ceiling: long enough for a real command, short enough to notice. */
    private const int QUICK_TIMEOUT = 60;

    /** The work ceiling, matching the HTTP endpoint's default. */
    private const int RUN_TIMEOUT = 1800;

    public function __construct(
        private readonly ExecRunner $runner,
    ) {
    }

    /**
     * @param list<string>               $cmd
     * @param array<string, string>|null $env
     *
     * @return array{exit_code: int|null, stdout: string, stderr: string, truncated: bool, timed_out: bool}
     */
    public function run(
        string $session,
        array $cmd,
        ?string $workdir = null,
        ?int $timeout = null,
        ?string $network = null,
        ?array $env = null,
    ): array {
        return $this->execute($session, $cmd, $workdir, $timeout ?? self::RUN_TIMEOUT, $network, $env);
    }

    /**
     * @param list<string>|null          $cmd
     * @param array<string, string>|null $env
     *
     * @return array{exit_code: int|null, stdout: string, stderr: string, truncated: bool, timed_out: bool}
     */
    public function quick(
        string $session,
        ?array $cmd = null,
        ?string $workdir = null,
        ?int $timeout = null,
        ?string $network = null,
        ?array $env = null,
    ): array {
        return $this->execute($session, $cmd ?? ['true'], $workdir, $timeout ?? self::QUICK_TIMEOUT, $network, $env);
    }

    /**
     * @param list<string>               $cmd
     * @param array<string, string>|null $env
     *
     * @return array{exit_code: int|null, stdout: string, stderr: string, truncated: bool, timed_out: bool}
     */
    private function execute(
        string $session,
        array $cmd,
        ?string $workdir,
        int $timeout,
        ?string $network,
        ?array $env,
    ): array {
        // `ExecRequest` is authoritative, exactly as it is on the HTTP path:
        // the schema the SDK validated against was derived from these same
        // rules, so a value arriving here is already well-shaped — and
        // "already validated" is not a reason to have a second, weaker parser.
        $request = ExecRequest::fromArray([
            'cmd' => $cmd,
            'workdir' => $workdir ?? Workdir::DEFAULT,
            'timeout' => $timeout,
            'network' => $network ?? NetworkMode::None->value,
            'env' => $env,
        ]);

        return self::shape($this->runner->run(SessionName::from($session), $request));
    }

    /**
     * The five facts a caller needs, in the shape the HTTP endpoint returns —
     * because "it failed", "it was killed" and "the output was cut" are three
     * different things, and the timeout design exists to keep them apart.
     * Output is scrubbed: a command writes arbitrary bytes and this has to be
     * encodable.
     *
     * @return array{exit_code: int|null, stdout: string, stderr: string, truncated: bool, timed_out: bool}
     */
    private static function shape(ExecResult $result): array
    {
        return [
            'exit_code' => $result->exitCode,
            'stdout' => Utf8::scrub($result->stdout),
            'stderr' => Utf8::scrub($result->stderr),
            'truncated' => $result->truncated,
            'timed_out' => $result->timedOut,
        ];
    }
}
