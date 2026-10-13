<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Janitor\Janitor;

/**
 * `reap` — the janitor on demand, as a tool.
 *
 * The same sweep the broker runs as its own child process and the same one
 * `POST /v1/reap` reaches, so asking for it over MCP asks the identical
 * question. The report is the report: `inspected` counts every broker-labelled
 * container the daemon listed, `reaped` and `failed` say what happened to each
 * one the sweep acted on.
 *
 * **Why it is not the destructive tool.** `delete_session` removes a session
 * and its volumes because a caller asked for that session to be gone. This
 * acts only on containers whose own `ww.ttl` has already expired — the label
 * each container carries says how long it was meant to live, and the sweep
 * compares that against the container's own age — and it never removes a
 * workspace volume, deliberately (the comment in `Janitor` says why). A sweep
 * is idempotent in the way that matters, so an always-available tool is safe.
 */
final class ReapTool
{
    public function __construct(
        private readonly Janitor $janitor,
    ) {
    }

    /**
     * @return array{inspected: int, reaped: list<array<string, mixed>>, failed: list<array<string, mixed>>}
     */
    public function reap(): array
    {
        return $this->janitor->sweep()->toArray();
    }
}
