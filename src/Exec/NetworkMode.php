<?php

declare(strict_types=1);

namespace App\Exec;

/**
 * The only two networks a command container may join.
 *
 * `none` is the default and means exactly that. `session` joins the session's
 * own broker-created network, which is how a compose stack's containers reach
 * each other.
 *
 * There is no `host` and no arbitrary network name, because the enum is the
 * whole vocabulary: "reach the network the Docker proxy is on" is not a value
 * that can be expressed (DOCKER-ACCESS.md §5).
 */
enum NetworkMode: string
{
    case None = 'none';
    case Session = 'session';

    /**
     * Resolve to a Docker network name.
     *
     * Session networks are named by the broker from the validated session name
     * (`ww-net-<session>`) and never by the caller, so this cannot be pointed at
     * somebody else's network.
     */
    public function dockerNetwork(string $sessionNetwork): string
    {
        return match ($this) {
            self::None => 'none',
            self::Session => $sessionNetwork,
        };
    }
}
