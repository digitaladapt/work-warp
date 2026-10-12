<?php

declare(strict_types=1);

namespace App\Stack;

use App\Docker\Labels;
use App\Docker\Limits;
use App\Exec\ExecRequest;
use App\Session\SessionName;

/**
 * The one place a stack service's `POST /containers/create` body is assembled.
 *
 * `ContainerSpec`'s argument, at a different altitude: field by field, from
 * typed input, so what cannot be expressed cannot be forwarded. Where this
 * spec differs from the command spec, it differs deliberately, and each
 * difference is the second-containment-class decision stated rather than
 * papered over:
 *
 * | Field | Command container | Stack service | Why |
 * |---|---|---|---|
 * | `CapDrop: ALL` | yes | **no** | A database image needs `CHOWN`,`SETUID`,`SETGID` to drop to its own user on first boot; dropping everything breaks every real service image. The structural containments below still hold. |
 * | `ReadonlyRootfs` | yes | **no** | A service writes where its image expects to (`/var/lib/postgresql`, package caches); a read-only rootfs with no tmpfs plan breaks it. Its *durable* state is on a named volume either way. |
 * | `Tmpfs` | `/tmp` | none | Only needed when the rootfs is read-only. |
 * | `User` | fixed `1000:1000` | **image default** | Same reason as caps: postgres must start as root to become postgres. |
 * | `NetworkingConfig` | — | **aliases on the session network** | This is how `web` is reachable as `http://web:80` from a command container; it is also why no port is ever published. |
 * | `Healthcheck` | — | from `health_cmd` | The mechanism behind the `healthy` condition. |
 *
 * What is identical to the command spec, and load-bearing: `Init`, no
 * `AutoRemove`, `no-new-privileges`, `PidsLimit`, `Memory`, `StopTimeout`,
 * the broker-stamped labels, and — the one that matters most — the *closed*
 * field set. There is no parameter of this method through which `Privileged`,
 * `CapAdd`, `Devices`, `PidMode`, `UsernsMode`, a host bind or a second mount
 * could arrive; `Binds` is computed solely from `StackMount`s, whose sources
 * are `StackVolumeName`s this broker creates.
 */
final class StackContainerSpec
{
    /**
     * Health-check timing, fixed policy rather than input — the same posture
     * as `Limits`. Interval and start period are seconds-apart on purpose:
     * a service that comes up in two seconds is not declared unhealthy while
     * it is still booting, and one that comes up in twenty is declared
     * healthy about when a human would say so.
     */
    private const int HEALTH_INTERVAL_NS = 2_000_000_000;
    private const int HEALTH_TIMEOUT_NS = 5_000_000_000;
    private const int HEALTH_START_PERIOD_NS = 5_000_000_000;
    private const int HEALTH_RETRIES = 15;

    /**
     * @return array<string, mixed>
     */
    public static function payload(
        StackService $service,
        SessionName $session,
        string $network,
        Limits $limits,
        int $ttl,
    ): array {
        $payload = [
            'Image' => $service->image,
            'Env' => self::envList($service, $session),
            'Labels' => Labels::stack($session, $service, $ttl),
            'Tty' => false,

            // The service is reachable as its own name on the session network
            // — `curl http://web:80` — which is the only reachability a stack
            // has. No port is ever published.
            'NetworkingConfig' => [
                'EndpointsConfig' => [
                    $network => ['Aliases' => [$service->name]],
                ],
            ],

            'HostConfig' => [
                'Init' => true,
                'AutoRemove' => false,

                'SecurityOpt' => ['no-new-privileges:true'],

                'PidsLimit' => $limits->pidsLimit,
                'Memory' => $limits->memoryBytes,

                // Always the session network; there is no other value.
                'NetworkMode' => $network,

                // Exactly the mounts the service declared, and each source is
                // a volume this broker creates. The list is not a parameter.
                'Binds' => self::binds($service, $session),

                'StopTimeout' => $limits->stopTimeout,
            ],
        ];

        // An empty command means "the image's own CMD", which is said by
        // omitting the field, not by sending [] — an empty array would
        // override the image's command with nothing.
        if ([] !== $service->command) {
            $payload['Cmd'] = $service->command;
        }

        if ([] !== $service->healthCmd) {
            $payload['Healthcheck'] = [
                'Test' => ['CMD', ...$service->healthCmd],
                'Interval' => self::HEALTH_INTERVAL_NS,
                'Timeout' => self::HEALTH_TIMEOUT_NS,
                'StartPeriod' => self::HEALTH_START_PERIOD_NS,
                'Retries' => self::HEALTH_RETRIES,
            ];
        }

        return $payload;
    }

    /**
     * The service's environment: what the caller declared, then the broker's
     * own names. The caller cannot collide with the broker's copy — `WW_` keys
     * and the broker-owned names are refused at request construction — so
     * ordering cannot matter, the same argument as `ContainerSpec::envList`.
     *
     * @return list<string>
     */
    public static function envList(StackService $service, SessionName $session): array
    {
        $env = [];

        foreach ($service->environment as $name => $value) {
            $env[] = $name.'='.$value;
        }

        $env[] = 'WW_SESSION='.$session->value;
        $env[] = 'WW_STACK_SERVICE='.$service->name;

        foreach (ExecRequest::BROKER_OWNED_ENV as $name => $value) {
            $env[] = $name.'='.$value;
        }

        return $env;
    }

    /**
     * @return list<string>
     */
    private static function binds(StackService $service, SessionName $session): array
    {
        $binds = [];

        foreach ($service->mounts as $mount) {
            $binds[] = $session->stackVolume($mount->source->value)
                .':'.$mount->target->value
                .':'.($mount->readOnly ? 'ro' : 'rw');
        }

        return $binds;
    }
}
