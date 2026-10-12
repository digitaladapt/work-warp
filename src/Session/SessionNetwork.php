<?php

declare(strict_types=1);

namespace App\Session;

use App\Docker\DockerApi;
use App\Docker\Labels;

/**
 * A session's network, created lazily and exactly once.
 *
 * Extracted when `build` became the second caller: `exec` creates the network
 * the first time a command asks to reach it, and a build with
 * `network: session` needs the same thing before the daemon will accept
 * `networkmode=ww-net-<session>`. One implementation, so the two callers
 * cannot drift on the parts that matter — the name (from `SessionName`, never
 * a caller) and the labels (from `Labels`, so ownership is readable).
 *
 * Lazy on purpose: a bridge network per session is cheap to create but
 * wasteful to keep for the sessions that never ask to reach anything — the
 * daemon's default address pool is finite.
 */
final class SessionNetwork
{
    public function __construct(
        private readonly DockerApi $docker,
    ) {
    }

    public function ensure(SessionName $session): void
    {
        $network = $session->network();

        if ($this->docker->networkExists($network)) {
            return;
        }

        $this->docker->createNetwork($network, Labels::network($session));
    }
}
