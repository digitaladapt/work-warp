# WorkWarp

**Warp: the persistent surface that ephemeral commands cross.**

A workspace, terminal and filesystem for long-running agent tasks — work that
runs for days or weeks toward a single large goal, where each individual command
is short-lived, disposable and untrusted.

```
SESSION    durable policy + identity, may live months
   │
   ├── WORKSPACE   persistent named volume (the only thing that survives)
   │
   └── COMMAND     ephemeral container per command → output → gone
```

The name is the weaving family's: **Work** (the substance) + **Warp** (the tool).
The warp is the long, strong set of threads on a loom, held under tension, that
everything else works across — durable, stretched over time, crossed once by each
ephemeral command and then left alone.

- Container prefix: `ww-`
- Labels: `ww.created-by`, `ww.session`, `ww.kind`, `ww.ttl`

## The shape of it

Three layers, with one rule: **state lives in the volume, never in a process.**

| Layer | What it is | Lives for |
|---|---|---|
| Session | policy + identity + the ledger | months |
| Workspace | a named volume, mounted at `/workspace` | as long as you want it |
| Command | one ephemeral container per command | seconds to minutes |

A session that lasts months cannot be a live process; long-lived processes leak.
So the durable objects are the volume and the ledger, and every command is a
fresh container that shares only the volume.

## The broker

The agent runs arbitrary commands but must not hold the Docker credential, so
Docker is reached through a broker that holds it instead. The broker's API is
built so that dangerous operations are **unexpressible rather than refused**:
there is no field in which to put `--privileged`, a bind mount, a device, a
capability, a host namespace, or an arbitrary container name. Not a denylist — a
vocabulary that lacks the words.

The second principle follows from the first: **constrain the container, not the
command.** `cmd` is an arbitrary argv array, deliberately. What is fixed is
everything the container is and can reach — no capabilities, a read-only rootfs,
one mount, its own network namespace, a hard clock.

Where it sits, and why each hop exists:

```
 public                    lyra_api                 lyra_backend
 ───────────────────       ─────────────────        ────────────────
 lyra-webui                lyra-terminal ────────▶  ww-broker ────▶ lyra-dockerproxy
 task-loom                 ww-broker                                     │
 context-shuttle, …                                                   dockerd
```

The session is **not** on the proxy's network. That is the load-bearing line:
a session that could reach the proxy directly could re-attach itself with one
request, and the broker would be advisory rather than enforced.

### What the broker is, and is not

It is **guardrails, not a boundary.** It stops accidents, namespaces resources,
injects labels and TTLs, and makes a whole class of escalation unrepresentable.
It is not a substitute for running the daemon in a VM, which is the layer that
defends against a container-escape CVE or a bug in the broker itself.

## Status

**Design complete; broker bootstrap in progress.** The design — decisions,
measurements and open questions — is in
[`docs/design/PLAN.md`](docs/design/PLAN.md), with the Docker-access milestone in
[`docs/design/DOCKER-ACCESS.md`](docs/design/DOCKER-ACCESS.md). Read those before
changing anything.

What exists today:

- `bin/ww-run` — the sanctioned command path from a terminal with no daemon
  route. Refuses the dangerous flags; stamps names and labels; reaps.
- `bin/probe-guardrails.sh` — measures what the socket proxy actually admits,
  rather than trusting prose about it.
- `src/Exec/`, `src/Session/`, `src/Docker/` — the typed request vocabularies
  and the one function that assembles a container-create payload, with the tests
  that hold them to it. `src/Docker/` also holds the client (`DockerApi` and
  `HttpDockerApi`, including the demultiplexing, capped log reader).
  `src/Session/SessionController.php` serves `POST /v1/sessions` — a session
  and its labelled workspace volume, with ensure semantics — and
  `src/Exec/ExecController.php` + `ExecRunner.php` serve
  `POST /v1/sessions/{name}/exec`: one ephemeral container per command, a slot
  held for the run, a hard deadline, demultiplexed capped output.
- **The scratch build path** (`src/Build/`) — `POST /v1/sessions/{name}/build`
  runs a real `docker build` from a tar context the caller streams, tagged
  `ww-<session>-<tag>:latest` and disposable by construction. The body is the
  context and the parameters ride in the query (`?tag=&network=&timeout=`), the
  same shape the daemon itself uses. `network: none | session` is the same
  enum exec uses; a build that needs egress joins the session network. The
  budget is the connection's (the daemon cancels a build whose client
  disconnects), failures keep the daemon's own two shapes (a mid-stream failure
  is a 200 with the diagnosis; a pre-stream refusal is a 502 with its words),
  and the outcome is confirmed by asking the daemon whether the image exists
  rather than parsing the log. **Pins `version=1`, the classic builder, for a
  measured reason** — BuildKit refuses custom networks, so it cannot express
  `network: session` (DOCKER-ACCESS.md §10 step 4a). Needs `BUILD: 1` at the
  socket proxy in deployment; that change is staged in `deploy/compose.yaml`
  and flagged in `deploy/README.md` as pending host application.**
- The `/v1` **bearer gate**, checked before routing: everything under `/v1`
  needs `WW_TOKEN`, and an unset token refuses rather than allowing.
- `src/Health/` — liveness and readiness.
- **The janitor** (`src/Janitor/`) — the sweep that makes "cannot sit there
  forever" true even when the broker is dead: containers running or sitting
  past their `ww.ttl` are stopped, and ones already down past it are removed
  with their anonymous volumes. `POST /v1/reap` sweeps on demand and reports;
  `php bin/console ww:janitor --every 60` sweeps on a loop as the broker's own
  child process; kills are written into the session's ledger.
- The FrankenPHP image, Caddy config, php.ini and entrypoint.
- **The command image** (`ww-base/`) — what a command container is made of:
  Debian trixie-slim and a general toolset, uid 1000 owning `/workspace`, no
  default command. Published by the same bake run as the broker as
  `:<TAG>-base` (`:develop-base`, `:latest-base`).
- The full quality gate: php-cs-fixer, PHPStan (level 6, empty baseline),
  PHPUnit, `composer audit`.

- **The compose harness** (`src/Stack/`) — `POST /v1/sessions/{name}/stack`
  brings up a real multi-service stack: `{"action": "up", "services": {...}}`
  with `down` and `logs` beside it. This is the answer to "how does a
  `docker compose up` test run when sessions have no daemon route?" — the
  broker renders and runs the stack itself from a **typed vocabulary**
  (compose altitude `ContainerSpec`), because a caller-supplied compose file
  can express `privileged`, host binds and devices, and that class is exactly
  what this design excludes. Services are reachable **by name** on the session
  network (`http://web:80` from an `exec` with `network: session`) — no port
  is ever published, so concurrent stacks cannot collide. `depends_on` gates
  on `started` / `completed` / `healthy`, resolved before anything is created;
  `up` holds a slot, `down`/`logs` never do; images are pulled when absent
  (permissions the deployment already grants — no proxy change needed).
- **The session surface** (`src/Session/`) — the rest of §5's session half:
  `GET /v1/sessions` lists every session from the workspace *label* (never a
  name prefix), `GET /v1/sessions/{name}` is one session's workspace,
  containers and ledger, `GET /v1/sessions/{name}/ps` is the containers
  alone, and `DELETE /v1/sessions/{name}` destroys the session in a
  dependency order — containers (with their anonymous volumes), then the
  network, then its volumes, the workspace last. `{"keep_workspace": true}`
  keeps every volume; the default leaves nothing. One refusing container is
  a reported failure, never a stranded teardown.

What does not exist yet: `stdin` delivery for `exec` (needs connection
hijacking — currently a `501`) and the MCP surface. See DOCKER-ACCESS.md §10
for the build order; `ww-base/README.md` records the command image's contract
and how to check it.

## Development

```bash
composer install
composer lint && composer stan && composer test
```

All four must pass before a PR is mergeable. Quality gates run in CI via the
shared workflow in `private/ci`; `composer audit` and a coverage floor are part
of it.

## Conventions

The house rules — branch naming, the PR workflow, and what this repo's design
rests on — are in [`AGENTS.md`](AGENTS.md). PHP 8.5, Symfony 8.1,
`declare(strict_types=1)` everywhere.

Prose in this repo is plain and specific. Prefer a verified observation over a
plausible claim, and say plainly when something is an assumption rather than a
finding. Where a claim was tested, say how.
