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
 *
 * **The parameter names here are the wire names, deliberately.** The SDK resolves
 * a call by matching each JSON argument to a *parameter of the same name* —
 * `Mcp\Capability\Registry\ReferenceHandler::prepareArguments()` reads
 * `$arguments[$parameter->getName()]` with no mapping layer between the schema
 * and the method — so `$session` and `$keep_workspace` are snake_case because
 * that is what the catalogue advertises. Renaming either back to camelCase
 * disconnects it from its argument, and the failure is not symmetrical: a
 * required parameter that no argument fills raises before the handler is entered,
 * while an *optional* one falls back to its default and the flag is dropped
 * without a word. `ToolArgumentNamesTest` holds every tool in the catalogue to
 * this, so the renaming cannot come back quietly.
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
    public function get(string $session): array
    {
        $name = $this->requireSession($session);
        $ledger = $this->ledger->read($name);

        return [
            'session' => $name->value,
            'workspace' => $name->workspaceVolume(),
            'containers' => self::containers($this->containers->forSession($name)),
            'ledger' => null === $ledger ? null : ['days' => $ledger['days'], 'today' => $ledger['today']],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function ps(string $session): array
    {
        $name = $this->requireSession($session);

        return self::containers($this->containers->forSession($name));
    }

    /**
     * @return array{containers_removed: list<string>, networks_removed: list<string>, volumes_removed: list<string>, failures: list<array{name: string, reason: string}>, workspace_removed: bool}
     */
    public function delete(string $session, bool $keep_workspace = false): array
    {
        return $this->destroyer->destroy(SessionName::from($session), $keep_workspace)->toArray();
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
    private function requireSession(string $session): SessionName
    {
        $name = SessionName::from($session);

        if (!$this->docker->volumeExists($name->workspaceVolume())) {
            throw NoSuchSession::named($name->value);
        }

        return $name;
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
