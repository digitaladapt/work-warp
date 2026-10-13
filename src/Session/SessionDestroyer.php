<?php

declare(strict_types=1);

namespace App\Session;

use App\Docker\DockerApi;
use App\Docker\DockerException;
use App\Docker\Labels;

/**
 * The destructive half of the session API: what `DELETE /v1/sessions/{name}`
 * removes, in what order, and the one rule that decides how much.
 *
 * **The rule, stated once.** Containers and the session's network are always
 * removed — they are the session's live resources and nothing else removes a
 * network. Volumes are removed *unless* `keep_workspace` says otherwise:
 * all of them (the workspace, and stack data) or none of them. A finer split
 * — keep the workspace, drop the stack's database — was considered and
 * rejected, because it would put irreversible deletion behind a flag whose
 * name reads as preservation, and any caller surprised by the result would
 * be surprised by lost data. `keep_workspace` therefore means: keep every
 * volume this session owns; false (the default) means nothing survives.
 *
 * **The order is a dependency order.** Containers first: a network with
 * attached endpoints cannot be removed, and a volume mounted by a container
 * cannot either. Containers go with `v=1`, so their anonymous volumes go too
 * (PLAN.md §8: anonymous volumes are what fills a disk). Then the network.
 * Then the session's other volumes, and the workspace **last** — it is the
 * one volume whose removal is what the caller is really deciding, so every
 * step that could refuse gets its chance before it.
 *
 * **Membership is decided by labels, never by names** — the `ww.session`
 * label, on containers and volumes both — and the listings this reads are
 * the client's own, already filtered to `ww.created-by=work-warp`, so a
 * neighbour's resources can never be in range whatever they are called.
 *
 * A session with no workspace volume is not created on the way past: the
 * caller gets `NoSuchSession`, the same refusal every other endpoint gives.
 *
 * **Best-effort within the deletion, deliberately.** One container that
 * refuses to go must not strand the network, the volumes or the rest of the
 * teardown; failures are collected and the HTTP status stays a 200 — the
 * shape the stack teardown established — because a partial deletion that
 * says what it managed and what it could not beats a 5xx that hides it.
 */
final class SessionDestroyer
{
    public function __construct(
        private readonly DockerApi $docker,
    ) {
    }

    public function destroy(SessionName $session, bool $keepWorkspace): SessionDeletion
    {
        $workspace = $session->workspaceVolume();

        if (!$this->docker->volumeExists($workspace)) {
            throw NoSuchSession::named($session->value);
        }

        $containers = [];
        $networks = [];
        $volumes = [];
        $failures = [];

        foreach ($this->docker->listContainers() as $container) {
            if (($container->labels[Labels::LABEL_SESSION] ?? null) !== $session->value) {
                continue;
            }

            try {
                $this->docker->removeContainer($container->id, removeAnonymousVolumes: true);
                $containers[] = $container->name;
            } catch (DockerException $e) {
                $failures[] = ['name' => $container->name, 'reason' => $e->getMessage()];
            }
        }

        // The network exists only if a command or a stack ever asked to reach
        // it — it is created lazily — so it is checked before it is removed,
        // and only a network that was actually there is reported as removed.
        $network = $session->network();

        try {
            if ($this->docker->networkExists($network)) {
                $this->docker->removeNetwork($network);
                $networks[] = $network;
            }
        } catch (DockerException $e) {
            $failures[] = ['name' => $network, 'reason' => $e->getMessage()];
        }

        if (!$keepWorkspace) {
            // Every volume the session owns, except the workspace — stack
            // data today, and anything session-scoped that gets a `ww.kind`
            // later. The workspace is removed separately, below, so that its
            // outcome is reported precisely whatever the listing said.
            foreach ($this->docker->listVolumes() as $volume) {
                if ($volume->name === $workspace
                    || ($volume->labels[Labels::LABEL_SESSION] ?? null) !== $session->value
                ) {
                    continue;
                }

                try {
                    $this->docker->removeVolume($volume->name);
                    $volumes[] = $volume->name;
                } catch (DockerException $e) {
                    $failures[] = ['name' => $volume->name, 'reason' => $e->getMessage()];
                }
            }
        }

        $workspaceRemoved = false;

        if (!$keepWorkspace) {
            try {
                $this->docker->removeVolume($workspace);
                $volumes[] = $workspace;
                $workspaceRemoved = true;
            } catch (DockerException $e) {
                $failures[] = ['name' => $workspace, 'reason' => $e->getMessage()];
            }
        }

        return new SessionDeletion($containers, $networks, $volumes, $failures, $workspaceRemoved);
    }
}
