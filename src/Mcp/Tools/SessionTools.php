<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Docker\DockerApi;
use App\Session\NoSuchSession;
use App\Session\SessionContainer;
use App\Session\SessionContainers;
use App\Session\SessionDestroyer;
use App\Session\SessionLedger;
use App\Session\SessionName;
use App\Session\SessionWorkspaces;

/**
 * The session tools: `list_sessions`, `get_session`, `ps`, `delete_session`.
 *
 * These are the HTTP session surface reached over MCP, and they are *the same
 * objects* rather than a re-implementation: `SessionWorkspaces` for the
 * definition of a session, `SessionContainers` for what a session owns,
 * `SessionLedger` for the read, `SessionDestroyer` for the teardown. A second
 * implementation of "what is a session" is the classic way two surfaces drift,
 * so there is exactly one.
 *
 * Two shapes worth stating, because a model sees them:
 *
 * - **`delete_session` is the one destructive tool.** It answers
 *   `{"containers_removed": […], "networks_removed": […], "volumes_removed":
 *   […], "failures": […], "workspace_removed": bool}` — the same report the
 *   endpoint returns — and its `keep_workspace` argument means what it says
 *   about *every* volume, all or none. Its MCP annotations mark it
 *   destructive, which is what a client uses to decide whether to ask first.
 * - **`get_session`'s ledger is best-effort.** A read that cannot happen is
 *   `null` beside a truthful container list, never an error — the session
 *   exists whether or not a convenience read is legible.
 */
final class SessionTools
{
    public function __construct(
        private readonly DockerApi $docker,
        private readonly SessionWorkspaces $workspaces,
        private readonly SessionContainers $containers,
        private readonly SessionLedger $ledger,
        private readonly SessionDestroyer $destroyer,
    ) {
    }

    /**
     * @return array{created: bool, session: string, workspace: string}
     */
    public function create(string $name): array
    {
        $session = SessionName::from($name);

        return [
            'created' => $this->workspaces->ensure($session),
            'session' => $session->value,
            'workspace' => $session->workspaceVolume(),
        ];
    }

    /**
     * @return list<array{session: string, workspace: string}>
     */
    public function list(): array
    {
        return $this->workspaces->all();
    }

    /**
     * @return array{session: string, workspace: string, containers: list<array<string, mixed>>, ledger: array{days: list<string>, today: string|null}|null}
     */
    public function get(string $name): array
    {
        $session = $this->requireSession($name);
        $ledger = $this->ledger->read($session);

        return [
            'session' => $session->value,
            'workspace' => $session->workspaceVolume(),
            'containers' => self::containers($this->containers->forSession($session)),
            'ledger' => null === $ledger ? null : ['days' => $ledger['days'], 'today' => $ledger['today']],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function ps(string $name): array
    {
        $session = $this->requireSession($name);

        return self::containers($this->containers->forSession($session));
    }

    /**
     * @return array{containers_removed: list<string>, networks_removed: list<string>, volumes_removed: list<string>, failures: list<array{name: string, reason: string}>, workspace_removed: bool}
     */
    public function delete(string $name, bool $keepWorkspace = false): array
    {
        return $this->destroyer->destroy(SessionName::from($name), $keepWorkspace)->toArray();
    }

    /**
     * A session that must exist, or a `ToolCallException` the model can read.
     *
     * The endpoint's `404` has no analogue in a tool call — a refusal is a
     * refusal — so "no such session" is raised as the same kind of error the
     * endpoint names, and the shared translation keeps the message. This is
     * deliberate: a mistyped name must not create a session on the way past,
     * which is the rule every other entry point already follows.
     */
    private function requireSession(string $name): SessionName
    {
        $session = SessionName::from($name);

        if (!$this->docker->volumeExists($session->workspaceVolume())) {
            throw NoSuchSession::named($session->value);
        }

        return $session;
    }

    /**
     * @param list<SessionContainer> $containers
     *
     * @return list<array<string, mixed>>
     */
    private static function containers(array $containers): array
    {
        return array_map(static fn (SessionContainer $c): array => $c->toArray(), $containers);
    }
}
