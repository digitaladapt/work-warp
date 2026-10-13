<?php

declare(strict_types=1);

namespace App\Session;

use App\Docker\DockerApi;
use App\Docker\Labels;
use App\Docker\VolumeSummary;

/**
 * What a session *is*, in one place, for every surface that asks.
 *
 * A session is a labelled workspace volume and nothing else (DOCKER-ACCESS.md
 * §4, §6), and that definition is now needed by two surfaces — the HTTP
 * endpoints and the MCP tools — which is exactly the point at which it stops
 * being a few lines in a controller and becomes a thing with a name.
 *
 * Two operations, and the split matters:
 *
 * - {@see ensure()} creates the workspace volume if it is not there and says
 *   which happened. That boolean is the whole of the "ensure semantics": a
 *   caller that adopts an existing session and one that created a fresh one
 *   differ only in the status they report, never in what they do.
 * - {@see all()} lists sessions by reading the volumes that carry
 *   `ww.kind=workspace` plus a parseable `ww.session`. The **label is the
 *   authority** — the name prefix is only what makes an answer readable, and a
 *   volume whose session label does not parse is skipped rather than guessed
 *   at, the same rule the janitor applies to containers.
 */
final class SessionWorkspaces
{
    public function __construct(
        private readonly DockerApi $docker,
    ) {
    }

    /**
     * Make sure the session's workspace exists.
     *
     * @return bool true when this call created it, false when it adopted one
     */
    public function ensure(SessionName $session): bool
    {
        $workspace = $session->workspaceVolume();

        if ($this->docker->volumeExists($workspace)) {
            return false;
        }

        $this->docker->createVolume($workspace, Labels::workspace($session));

        return true;
    }

    /**
     * Every session this deployment holds, ordered by name.
     *
     * @return list<array{session: string, workspace: string}>
     */
    public function all(): array
    {
        $sessions = [];

        foreach ($this->docker->listVolumes() as $volume) {
            $name = self::sessionNameOf($volume);

            if (null === $name) {
                continue;
            }

            $sessions[] = ['session' => $name, 'workspace' => $volume->name];
        }

        usort($sessions, static fn (array $a, array $b): int => strcmp($a['session'], $b['session']));

        return $sessions;
    }

    /**
     * A listed volume's session name, when it is a workspace.
     *
     * The label is the authority: `ww.kind=workspace` and a `ww.session` value
     * are what make a volume a session. The name prefix is never consulted, so
     * a volume someone else named `ww-ws-…` cannot present itself as one.
     */
    private static function sessionNameOf(VolumeSummary $volume): ?string
    {
        if (($volume->labels[Labels::LABEL_KIND] ?? null) !== Labels::KIND_WORKSPACE) {
            return null;
        }

        $session = $volume->labels[Labels::LABEL_SESSION] ?? null;

        if (null === $session) {
            return null;
        }

        try {
            SessionName::from($session);
        } catch (InvalidSessionName) {
            return null;
        }

        return $session;
    }
}
