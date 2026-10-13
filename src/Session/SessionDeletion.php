<?php

declare(strict_types=1);

namespace App\Session;

/**
 * What a session deletion removed, and what it could not.
 *
 * The same shape as the stack teardown's `DownResult`, extended by the two
 * facts only a session has: its network (nothing else removes one) and
 * whether the workspace volume — the thing a session *is* — went too.
 *
 * `workspace_removed` is reported rather than inferred because it is the
 * question the caller actually asked: with `keep_workspace` it is false by
 * design, and without it a false value means a real failure, which is also
 * named in `failures` with the daemon's words.
 */
final class SessionDeletion
{
    /**
     * @param list<string>                              $containers
     * @param list<string>                              $networks
     * @param list<string>                              $volumes
     * @param list<array{name: string, reason: string}> $failures
     */
    public function __construct(
        public readonly array $containers,
        public readonly array $networks,
        public readonly array $volumes,
        public readonly array $failures,
        public readonly bool $workspaceRemoved,
    ) {
    }

    /**
     * @return array{containers_removed: list<string>, networks_removed: list<string>, volumes_removed: list<string>, failures: list<array{name: string, reason: string}>, workspace_removed: bool}
     */
    public function toArray(): array
    {
        return [
            'containers_removed' => $this->containers,
            'networks_removed' => $this->networks,
            'volumes_removed' => $this->volumes,
            'failures' => $this->failures,
            'workspace_removed' => $this->workspaceRemoved,
        ];
    }
}
