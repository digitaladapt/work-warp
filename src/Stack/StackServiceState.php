<?php

declare(strict_types=1);

namespace App\Stack;

/**
 * One service, as `up` left it: what it is, which container holds it, and
 * whether that container is running now.
 *
 * `container` is the broker's own container name (`ww-<session>-<service>-1`)
 * rather than an id, because the name is what the caller will use everywhere
 * else — `exec` against the network, `logs` on the next call, `docker ps` in
 * front of a human — and it is deterministic, so there is nothing to remember.
 */
final class StackServiceState
{
    public function __construct(
        public readonly string $name,
        public readonly string $container,
        public readonly string $image,
        public readonly bool $running,
    ) {
    }
}
