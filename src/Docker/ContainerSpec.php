<?php

declare(strict_types=1);

namespace App\Docker;

use App\Exec\ExecRequest;
use App\Session\SessionName;

/**
 * The one place a `POST /containers/create` body is assembled.
 *
 * Field by field, from typed input. That is the whole security argument of the
 * broker and it is worth stating plainly: an endpoint filter can be bypassed by
 * a request nobody anticipated, whereas a payload built from known keys cannot
 * express `Privileged`, `CapAdd`, `PidMode`, `Devices`, `UsernsMode` or a
 * second bind mount, because there is no input that produces them
 * (DOCKER-ACCESS.md §2, §7).
 *
 * Nothing here reads the broker's environment. An inherited environment landing
 * in a container the agent controls is the same class of leak as the
 * `/proc/1/environ` exposure that started this project, one layer up.
 *
 * The container's *name* is deliberately absent: Docker takes it as a query
 * parameter on create, not as a body field, so it is built by the runner from
 * SessionName and never appears here.
 */
final class ContainerSpec
{
    public const string WORKSPACE_MOUNT = '/workspace';
    public const string SESSION_ENV = 'WW_SESSION';

    /**
     * @return array<string, mixed>
     */
    public static function payload(
        string $image,
        SessionName $session,
        ExecRequest $request,
        string $workspaceVolume,
        Limits $limits,
    ): array {
        return [
            'Image' => $image,
            'Cmd' => $request->cmd,
            'WorkingDir' => $request->workdir->absolute(),
            'Env' => self::envList($request, $session),
            'Labels' => self::labels($session, $request, $limits),

            // No PTY. A captured output channel is not a terminal, and claiming
            // to be one is what left three pager processes alive for three days
            // (PLAN.md §2.3, §5.1).
            'Tty' => false,

            // Declared honestly: stdin is open exactly when the request carries
            // stdin. Writing the bytes in is the runner's job, with the attach
            // path.
            'OpenStdin' => null !== $request->stdin,
            'StdinOnce' => null !== $request->stdin,

            'HostConfig' => [
                // PID 1 reaps, so a command that forks does not leave zombies.
                'Init' => true,

                // The broker reads the exit code and the logs, then removes the
                // container itself. `--rm` can delete a container before either
                // is read, so it is not used here.
                'AutoRemove' => false,

                'CapDrop' => ['ALL'],

                // `:true` is the only form the daemon accepts for this option,
                // and it is the form `docker run --security-opt
                // no-new-privileges` normalises to. Setuid binaries inside a
                // command image therefore gain nothing.
                'SecurityOpt' => ['no-new-privileges:true'],

                // Nothing persists outside the workspace mount. "Write to / and
                // leave it" stops being possible rather than being discouraged.
                'ReadonlyRootfs' => true,
                'Tmpfs' => $limits->tmpfs,

                'PidsLimit' => $limits->pidsLimit,
                'Memory' => $limits->memoryBytes,
                'NanoCpus' => $limits->nanoCpus,

                // `none` unless the caller asked for the session's own network.
                // There is no third value, and no way to name a network.
                'NetworkMode' => $request->network->dockerNetwork($session->network()),

                // Exactly one mount, computed from the validated session name.
                // The mount list is not a parameter.
                'Binds' => [$workspaceVolume.':'.self::WORKSPACE_MOUNT.':rw'],

                // Bounded shutdown, so a stop cannot hang on a wedged process.
                'StopTimeout' => $limits->stopTimeout,

                // Numeric on purpose: a name would depend on the image having a
                // user with that name, while the workspace volume is owned by a
                // number.
                'User' => $limits->user,
            ],
        ];
    }

    /**
     * The container's environment: what the caller declared, plus the one
     * variable the broker adds, and nothing else.
     *
     * @return list<string>
     */
    public static function envList(ExecRequest $request, SessionName $session): array
    {
        $env = [];

        foreach ($request->env as $name => $value) {
            $env[] = $name.'='.$value;
        }

        $env[] = self::SESSION_ENV.'='.$session->value;

        return $env;
    }

    /**
     * @return array<string, string>
     */
    public static function labels(SessionName $session, ExecRequest $request, Limits $limits): array
    {
        return [
            Labels::LABEL_CREATED_BY => Labels::CREATED_BY,
            Labels::LABEL_SESSION => $session->value,
            Labels::LABEL_KIND => Labels::KIND_CMD,

            // The request's own timeout plus a grace period: what the janitor
            // enforces if the broker never gets to reap it.
            Labels::LABEL_TTL => (string) ($request->timeout + $limits->ttlGrace),
        ];
    }
}
