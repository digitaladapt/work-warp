# Changelog

All notable changes to WorkWarp are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning: [SemVer](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security

- **The bearer gate stepped aside for a percent-encoded path, so `/v1` was
  reachable without the token.** The gate compares `Request::getPathInfo()`,
  which is the *still-encoded* path, while the router decodes before matching
  (`UrlMatcher::match()` runs `rawurldecode()`). Encoding one character
  therefore made a request look like a non-`/v1` request to the gate and like a
  `/v1` request to the router: `GET /v1%2fsessions` and `GET /%76%31/sessions`
  listed sessions, `POST /v1%2fsessions` created one, and `POST /v1%2fmcp`
  completed an MCP `initialize` and issued a session id — all with no
  `Authorization` header, measured against the reference deployment on
  `warp.devgnome.com`. The gate now decodes with the same function the router
  uses, exactly once, so the two cannot disagree about what a `/v1` request is;
  `/v1%252fsessions` stays a `404`, because a single decode leaves it outside
  `/v1` and that is what routing says too. The unit and kernel-level gate tests
  now cover the encoded forms, which the existing cases did not — every one of
  them used a path that was already decoded.

### Added

- **`bin/probe-broker.sh` — the broker measured, not described.** The proxy has
  had `probe-guardrails.sh` since the first rollout, on the principle that a
  claim about a running stack should be re-measured rather than trusted; the
  broker had nothing, and the applied rollout immediately showed why it needed
  something. Run on the host, it answers in one pass: is the container up, does
  the terminal share a network with it, does the name `deploy/README.md` tells
  clients to use actually resolve, does `/v1` refuse a caller with no token and
  answer one with it, and — the check that has no other symptom — does the
  running image carry `pcntl`. It prints a verdict and exits 0/1/2, with 2
  meaning "some check could not be made", which is deliberately not a pass.

- **The extensions the broker needs at run time, and the test that keeps them.**
  `Dockerfile` installs `pcntl` and `intl` in the runtime stage; `BrokerImageTest`
  asserts they are there, that the stage which runs composer is *not* the stage
  that installs them, and that the janitor still subscribes to signals. See
  "Fixed" below for the failure that made this worth a guard.

### Fixed (the running stack, 2026-10-14)

- **The sweep would have died silently in the published image, for want of one
  extension.** `ww:janitor` implements `SignalableCommandInterface`, so the loop
  cannot start without `pcntl` — Symfony's `Application::getSignalRegistry()`
  throws "Signals are not supported" — and nothing in the repository required
  the extension, so no build would have failed over it. The entrypoint runs the
  loop in a `while true` + `sleep 5` subshell, which turned that into a failure
  with no visible symptom: the command died at start, the subshell slept, and it
  died again, every five seconds, while the broker answered `/health` and
  `/v1/pkg` normally. The only thing that stopped was the TTL sweep — the
  backstop under every command container, and the thing that makes "cannot sit
  there forever" true when the broker is dead. Found while reconciling the
  rollout record after the broker came up on 2026-10-14, having been added by
  hand on the host to get it running; reproduced by running the loop with the
  `pcntl_*` functions disabled, and confirmed working with the extension
  present.

- **`deploy/README.md` described a broker nothing could reach.** The rollout
  section claimed `lyra-broker` and the terminal shared `lyra_api`; measured
  2026-10-14, `lyra-broker` does not resolve from inside `lyra-terminal` at all
  (`lyra-terminal` does, so the terminal's own network DNS is answering — the
  broker is simply on none of its networks). Clients configured with the name
  the document gives them fail with a name-resolution error, which reads as a
  broken broker rather than a wrong name. The document now records this, along
  with the two facts that follow from it (nothing on the LAN serves `:8300`, and
  the SSH-pipe route needs the name to resolve where sshd runs), and names the
  check that settles the cause — a second compose project puts the broker on its
  own `<project>_api` bridge. The rollout heading says "applied" rather than
  "staged", and the two curl steps in the client section now carry the bearer
  header on the handshake as well as on the call.

### Added (continued)

- **The MCP surface, and the package around it — step 5 (DOCKER-ACCESS.md §10).**
  `src/Mcp/`, built over the settled broker API as the design required, using
  the official `mcp/sdk` (0.8.1) exactly as ContextShuttle does — which is the
  "borrow the plumbing, not the ceiling" direction §5a asked for, now that the
  API is what leads.

  **`POST /v1/mcp`** speaks streamable HTTP. It lives **under `/v1` on purpose**:
  the bearer gate covers `/v1` and only `/v1`, and this endpoint can start
  stacks and run arbitrary commands, so an `/mcp` outside the gate would be
  unauthenticated remote command execution. `/v1/mcp` matches the gate's own
  `str_starts_with('/v1/')` test.

  **Ten tools**, each a thin call into the object that already owned the
  operation — there is no MCP-specific business logic and no second
  implementation of anything:

  | Tool | Calls | Hint |
  |---|---|---|
  | `run_command` | `ExecRunner` | not idempotent |
  | `quick_command` | `ExecRunner` (1-minute ceiling, `cmd` defaults to `true`) | not idempotent |
  | `create_session` | `SessionWorkspaces::ensure` | idempotent |
  | `list_sessions` | `SessionWorkspaces::all` | read-only |
  | `get_session` | `SessionContainers` + `SessionLedger` | read-only |
  | `ps` | `SessionContainers` | read-only |
  | `delete_session` | `SessionDestroyer` | **destructive** |
  | `build_image` | `BuildRunner` | open-world |
  | `stack` | `StackRunner` | open-world |
  | `reap` | `Janitor` | idempotent |

  Decisions, all deliberate:

  - **Two command tools, and the docblock says it is not a privilege difference.**
    `cmd` is arbitrary by design, so the schema cannot say what a good command
    is; what a model can act on is the difference the names carry. Both build
    the same `ExecRequest` and reach the same runner.
  - **`stdin` is absent from both**, because the endpoint cannot honour it
    (`501`, no connection hijacking) and a schema field that always fails is a
    lie to the model. The workaround is in the description.
  - **Exit codes are results, not errors.** A command that exits non-zero is a
    success carrying `exit_code`; only a refusal *about the request* — no such
    session, no free slot, an unrepresentable name, the daemon saying no —
    becomes a tool error, and it carries the broker's own sentence rather than
    the SDK's generic "Error while executing tool". That translation is one
    class (`McpToolErrorTranslator`), the same point-every-handler-passes-through
    idea ContextShuttle uses, with the engine's vocabulary as its allow-list.
  - **Sessions are cached, not in-memory**, because the MCP handshake spans two
    requests; the pool is a named `mcp_sessions` one so it can move to Redis
    without touching the app cache.
  - **The tool set is a catalogue, not discovery.** No directory scanning: what
    the server offers is one readable list (`McpPackageCatalogue`), and the
    package manifest is generated from it.

  **The package, because an endpoint is not an integration.** `ww://pkg` is an
  MCP resource and `GET /v1/pkg` (+ `/v1/pkg/tools`) is the same document over
  plain HTTP: where the endpoint is, **that it is bearer-gated** (a client that
  does not know will read the `401` as a broken server), what a session is and
  that one must exist first, the shape of a command, **what is deliberately
  absent** (`stdin`, mounts, host networking) so those read as decisions, and
  the tool list. It is built from the same catalogue, so it cannot describe a
  tool that is not there.

  **The deployment artifact, staged.** `deploy/compose.yaml` gains the
  `lyra-broker` service — two networks (`api` for callers, `backend` for the
  proxy), `init`, resource caps, `:develop` pinned — plus
  `deploy/broker.env.example` and a `.gitignore` rule so the real secrets file
  cannot be committed. Staged rather than applied: the compose file mirrors the
  host, and `deploy/README.md` carries the rollout steps.

  Tests: `McpApiTest` drives the real handshake, `tools/list`, a `tools/call`
  that reaches the runner, the refusal path, schema validation refusing an
  empty `cmd` before Docker is touched, and the manifest over both transports.
  Full suite **369 tests / 1124 assertions**.

  **Deployment dependency:** `mcp/sdk` 0.8.1 (plus `opis/json-schema`,
  `opis/uri`, `symfony/uid` transitively) and `psr/simple-cache` — the latter
  required explicitly because `symfony/cache`'s `Psr16Cache` needs the
  interface, and without it every MCP request 500s (measured).

- **The session surface — the rest of §5's session half (PR #19).** Four endpoints
  beside the create, all in `src/Session/SessionController.php`:

  - `GET /v1/sessions` — every session, discovered from the volumes carrying
    `ww.kind=workspace` plus a parseable `ww.session`. The label is the
    authority; a name prefix is what a caller *could* type, and this code
    never reads one. Stack volumes and unlabelled volumes share the listing
    and are skipped.
  - `GET /v1/sessions/{name}` — one session: `{session, workspace,
    containers, ledger}`. Containers are label-filtered and sorted by name;
    the ledger is today's file plus the days present.
  - `GET /v1/sessions/{name}/ps` — the containers alone — what `ww-run ps`
    printed, minus the table.
  - `DELETE /v1/sessions/{name}` — destruction. The body is optional;
    `{"keep_workspace": true}` keeps every volume.

  **The ledger read is a disposable container, like the write.** It lives
  inside the workspace volume, so `SessionLedger` reads it exactly the way
  the janitor writes to it — a tiny container with the workspace mounted,
  the same constraints and the same image as any command. There is no second
  read path and no privileged exception for "just reading". The read is
  best-effort: a ledger that cannot be read is `null` in an otherwise true
  answer, never a 5xx.

  **The deletion has one rule, stated once.** Containers and the network are
  always removed; volumes are all-or-none under `keep_workspace`. A finer
  split (keep the workspace, drop the stack's data) was considered and
  rejected: it would put irreversible deletion behind a flag whose name
  reads as preservation. The order is a dependency order — containers first
  (a network with attached endpoints is refused, a mounted volume is
  refused), with `v=1` so anonymous volumes go too; then the network; then
  the session's other volumes; then the workspace, **last**, so every step
  that could refuse has had its chance. One refusing container is a
  reported failure in a `200`, not a stranded teardown — the same shape the
  stack's `down` established.

  `DELETE` of a missing session is a `404`, deliberately: "ensure" is a POST
  idea, and a deletion that reports triumph for a name that never existed
  teaches the caller the wrong thing about its own state. A session that
  never made a network (they are created lazily) reports none removed.

  **The Docker seam grew one method** — `removeNetwork`, the pair of
  `createNetwork` that `exec` and `build` had already made necessary in
  reverse. It forwards through the deployed proxy unchanged (`NETWORKS: 1`
  covers the section; v0.5.0 does not gate on method — measured 2026-10-07).

  Tests: `SessionDestroyerTest` pins the order, the membership rule and the
  all-or-none rule through the scripted daemon's call log;
  `SessionSurfaceApiTest` runs all four endpoints through the real kernel
  and gate. Full suite **358 tests / 1064 assertions**.

- **The compose harness — step 4b (DOCKER-ACCESS.md §10).**
  `POST /v1/sessions/{name}/stack` — one endpoint, three actions
  (`up`/`down`/`logs`), bringing a real multi-service stack up, down and into
  one log artifact. New `src/Stack/` (typed request and services, the plan,
  the container spec, the runner, the controller) plus three Docker-seam
  additions (`pullImage`, `listVolumes`, `removeVolume`).

  **The design answer, because step 4b asks a question rather than naming a
  feature:** `docker compose` is a Docker API client and a session has no
  daemon route by design, so the broker must run the stack — and the broker
  must *not* run a caller-supplied compose file, because a compose file can
  say `privileged: true`, `network_mode: host`, `volumes: [/:/host]`,
  `devices:`. Validating one would be filtering, which this project has ruled
  out once already. So the harness is a **typed vocabulary the broker renders
  and executes itself** — compose at the altitude of `ContainerSpec`. The
  compose-CLI path was measured first (`config` validates a rendered file,
  labels scope a project) and is recorded as a named upgrade for the
  *executor*: the caller-facing gate is the vocabulary either way.

  Decisions, all measured or asserted:

  - **Reachability by name dissolves PLAN §9's port landmine.** Services are
    aliased on the session network; the e2e reaches `http://web:80` from an
    `exec` with `network: session`. No port is ever published, so two stacks
    cannot collide and nothing binds the host.
  - **The second containment class, stated.** Stack services do not get
    `CapDrop: ALL`/read-only rootfs/fixed uid — every real service image
    breaks under those. They get: mounts whose sources are broker-created
    named volumes (host paths and the socket are unexpressible),
    `no-new-privileges`, pids/memory/stop limits, `Init`, the session network
    and only it. Both the absences and the presences are asserted
    (`StackContainerSpecTest`).
  - **Resolve before acting.** `StackPlan` refuses unknown dependencies,
    dependency cycles and `healthy` on a service with no health check *before
    anything is created*, so a bad graph is a `422` rather than a half-started
    stack.
  - **`up` holds an exec slot; `down` and `logs` never do** — teardown reduces
    load and a log read adds none, and refusing either while the budget is
    full is exactly when the caller needs them.
  - **Images are pulled when absent, never when present**, and the pull's
    outcome is asked (`imageExists`), never parsed — verified live against a
    real daemon, where a failed layer registration surfaced as a failed pull
    with the daemon's own words. The pull path (`POST /images/create`) is
    already forwarded by the deployed proxy, so **no deployment change** is
    needed for this step.
  - **`down` defaults to removing stack volumes** (the opposite of
    `docker compose down`): this endpoint is a test teardown, and a leftover
    database is how two runs contaminate each other. `keep_volumes: true` is
    the inspecting case.

- **The scratch build path — step 4a (DOCKER-ACCESS.md §10).**
  `POST /v1/sessions/{name}/build` runs a real `docker build` from a tar
  context the caller streams, tagged `ww-<session>-<tag>:latest`. The request
  body *is* the context and the parameters ride in the query
  (`?tag=intl&network=session&timeout=1800`) — the same shape the daemon itself
  uses (`POST /build?t=…` with a tar body), so the broker's edge and its
  upstream agree about where each thing lives. New `src/Build/` (typed request,
  capped context stream, runner, controller) and `src/Docker/BuildSpec.php`
  (the one place a build query is assembled, field by field, from typed input —
  `remote`, `extrahosts`, `cachefrom`, `buildargs`, `outputs`, `platform` and
  `dockerfile` are not expressible).

  Decisions, all measured against Docker 29.8 rather than assumed:

  - **`version=1`, the classic builder, deliberately.** BuildKit refuses
    `networkmode=<custom network>` ("network mode … not supported by buildkit");
    the classic builder accepts it, and `network: session` is the whole point
    of a non-hermetic build. The classic builder is deprecated upstream, so
    `BuildSpec`'s `version` line is the one to revisit when it is removed; the
    reopen condition is BuildKit gaining custom-network support.
  - **The budget is the connection.** The caller's timeout is set as both the
    idle and absolute HTTP timeout, and the daemon cancels a build whose client
    disconnects — the only stop this endpoint has, and weaker than exec's
    kill-at-deadline. A timed-out build is a 200 with `timed_out: true`, the
    same rule as a killed command.
  - **The outcome is asked, not parsed.** After the stream the broker asks the
    daemon whether the tagged image exists (tagging only happens on success) —
    except when the daemon reported a failure, where the lookup is skipped so a
    stale artefact from a previous build cannot be attributed to the failure.
  - **Hitting the output cap does not cancel the build.** Cancelling the
    response disconnects the client, which is exactly how the daemon cancels a
    build; past the cap the reader keeps reading and stops keeping, and says
    `truncated`.
  - **Tag grammar is the daemon's own reference grammar**, probed: `x-`, `x..y`,
    `.x`, `x:tag`, `a/b` and uppercase are refused by the daemon, so they are a
    `422` here rather than a `502` there.
  - **Failures keep the daemon's own distinction, because it has one.** A
    build that runs and fails mid-stream (a `RUN` exiting non-zero) is a `200`
    with `image: null` and the diagnosis in `output` — the same rule as a
    killed command. A build the daemon refuses *before* streaming (a malformed
    query, or an environment where it cannot start one — measured: `unshare:
    operation not permitted`) is an HTTP error from the daemon and becomes the
    broker's `502`, carrying the daemon's words. It did not run, and calling
    that a "failed build" would say it did. The reader classifies both from a
    real daemon's behaviour, verified live during development, not only against
    the mock.
  - The context is capped at 128 MiB, enforced twice: on the declared
    `Content-Length` when present (refused before reading), and by counting
    real bytes while streaming (a chunked request declares nothing, and a
    declaration is a claim, not a measurement).

  **Deployment note:** the socket proxy gates `/build` behind `BUILD` and it
  was unset, so `deploy/compose.yaml` now carries `BUILD: 1` and
  `bin/probe-guardrails.sh` reports `/build` as information (reachable is the
  intended end state now; blocked means the variable has not been applied yet).
  Until the host applies it, the endpoint answers `502` — the proxy refusing
  `/build`. `deploy/README.md` records the change as pending.

  The `Utf8` scrubber moved from a private `ExecController` helper to
  `src/Support/Utf8.php`, shared by both endpoints, and `SessionNetwork`
  (`src/Session/`) was extracted when build became the second caller that needs
  a session's network created lazily.

- **The janitor and `reap` — step 3 (DOCKER-ACCESS.md §10).** This is where
  "cannot sit there forever" becomes true even when the broker is dead. Two
  shapes, one engine: `POST /v1/reap` sweeps on demand and returns the report
  (`{inspected, reaped, failed}`), and `ww:janitor --every N` loops — the
  broker's own child process, started by the image entrypoint, so there is no
  cron and no second service to deploy (§6a).

  The sweep is one clock per container, read from the container's own
  `ww.ttl`: a container **running past its TTL is stopped** via
  `POST /containers/{id}/stop` — the daemon's own SIGTERM, wait for the
  container's `StopTimeout`, then SIGKILL, checked against moby's `stop.go`
  rather than assumed — and one **already down past its TTL is removed** via
  `DELETE ?force=1&v=1`, because §8 is explicit that anonymous volumes and
  dangling state, not running containers, are what fills a disk. One action
  per container per sweep: the next sweep removes what the previous one
  stopped, so every report line says exactly what happened.

  The race §8 worries about is closed by arithmetic, not by asking the
  runner. A live `ExecRunner` holds a container for at most its timeout plus
  the ten-second kill grace; `ww.ttl` is that same timeout plus `ttlGrace`
  (300 s by default). A container past its TTL therefore has no live consumer
  by construction. A container with no *readable* TTL is left alone rather
  than guessed at, and the pattern that reads the label is anchored with
  `\z`, not `$` — `"300\n"` is not a TTL.

  Kills are written into the session's ledger (§8 -> §6.2), through the
  *existing* disposable-container path — a tiny `ExecRunner` command with the
  workspace mounted, same image and same constraints as any other command.
  There is no second write path and no privileged exception for "just the
  janitor"; the write is best-effort, because a ledger line that cannot be
  written must never leave a container running.

  The three Docker calls the sweep makes — the label-filtered list, `stop`,
  `remove` — are all within what the socket proxy admits: `CONTAINERS: 1`
  covers the list, and `POST /containers/{id}/stop` and `DELETE` are
  forwarded, because v0.5.0 does not gate on method. That was measured in
  `deploy/README.md` while the pin was chosen; this feature is the first that
  depends on it.

- **`DockerApi::listContainers()` and `stopContainer()`, and
  `removeContainer`'s `v` parameter.** The list is filtered to the broker's
  own labels *inside the client* — the filter is built from `Labels` and
  never passed in, so the one cleanup path this broker has cannot be aimed at
  a neighbour's container. The list call is also the first Docker read that
  reduces the daemon's payload into a value object (`ContainerSummary`) with
  the same discipline as `ContainerStatus`.

- **The command image, `ww-base/` — the half of step 2 that was missing.**
  The design's "nothing runs end to end until it exists" image: Debian
  trixie-slim plus a general toolset (git and openssh-client, the archive
  family, Python 3 with its full standard library *and* `venv`, dig and ping,
  jq, less, file, rsync), built and published by the same bake run as the
  broker (`docker-bake.hcl`, target `base`) as suffixed tags of the same
  repository — `:develop-base` on a push to main, `:latest-base` and
  `:<version>-base` on a release.

  Two properties are load-bearing rather than convenient. **`/workspace`
  ships owned by `1000:1000`**: commands run as that uid against a read-only
  rootfs and nothing inside a container can chown, so a fresh volume becomes
  writable only because the daemon's volume-population step copies the image
  directory into the empty volume and brings its ownership along (traced to
  `fs.copyFileInfo` in containerd/continuity). Ship it root-owned and every
  session's first command dies on a permission error that looks like a broker
  bug. And **no default command** — `ENTRYPOINT []` and `CMD []` clear
  `debian:trixie-slim`'s inherited `Cmd: ["bash"]`, so a bare create is
  refused instead of quietly starting a shell nobody asked for.

  The daemon-free parts of that contract are a test
  (`tests/Unit/Image/CommandImageTest.php`: ownership, empty defaults,
  publication tags, the committed `WW_IMAGE`); the parts only a daemon can
  answer are `ww-base/smoke.sh`, which runs the production shape
  (`--read-only`, `--cap-drop=ALL`, `--no-new-privileges`, `--network none`,
  uid 1000) against a **fresh** volume — the only way to exercise volume
  population.

- **`exec` — the 95% win (DOCKER-ACCESS.md §10 step 2).**
  `POST /v1/sessions/{name}/exec` runs one command in one ephemeral container
  and returns `{exit_code, stdout, stderr, truncated, timed_out}`. The
  lifecycle is `ExecRunner`'s and follows the design's order: the session must
  already exist (a mistyped name is a `404`, never a new durable object), a
  slot is held for the whole run, the session's network is created lazily the
  first time a command asks to reach it, the container is built by
  `ContainerSpec` and started, the broker waits against its own deadline,
  kills at expiry, reads the capped logs, and removes the container before
  the slot goes back.

  Two choices are load-bearing and deliberate. **Polling rather than a
  blocking wait**: `POST /containers/{id}/wait` would hold a request open for
  the length of a test suite and hand the timeout to whatever client happened
  to be in the middle; the loop polls the injected clock instead, which keeps
  the deadline the broker's and the loop testable in microseconds. **A hard
  kill at the deadline**: a command that outran its timeout is over, the exit
  code says how (137), and `timed_out: true` says why — a timeout is a `200`
  with a flag, not a `5xx`, because a killed command ran.

  The failure vocabulary is the one the session endpoint established, extended
  where the design already said what it means: `404` no such session, `422`
  anything inexpressible (`SessionName` / `ExecRequest`), `429` when every slot
  is taken (the broker does not queue, §6b), `501` for `stdin`, `503`/`502`
  for the daemon unreachable / refusing.

- **`stdin` is refused with `501`, on purpose, and the field stays.**
  Delivering it needs the daemon's connection-hijacking `attach` path
  (Docker's own spec: the request upgrades and the socket goes raw), which the
  broker's HTTP client does not implement. The alternative was worse: a
  broker that accepted `stdin` and silently ran the command without it looks
  like a command that read an empty file. The refusal names the workaround
  (a file in the workspace) and the field keeps its validation, so lifting
  this is connection-hijack support rather than an API change.

- **The pager guard is the broker's, and the caller cannot take it.**
  `PAGER=cat GIT_PAGER=cat TERM=dumb NO_COLOR=1 CI=1` are stamped on every
  command — PLAN.md §5.1's direct fix for the three-day-old pagers — and the
  names are now *refused* in a request's `env`: "who wins when a variable is
  duplicated" is decided differently by each libc (glibc's `getenv` returns
  the first match), and a guard that can be overridden is not a guard.

- **The exec budget, enforced by the filesystem rather than by a counter.**
  `App\Exec\Slots` is a pool of N file locks — one slot per permitted concurrent
  command — so "how many are running" is "how many slots are taken" and a
  crashed holder frees its slot by the kernel closing a file descriptor. There
  is nothing to reconcile after a restart, because nothing was ever counted in a
  variable. `reserve()` refuses rather than queueing, with a message that says
  so; waiting is opt-in.

  The unit is the **workspace**, not the session. Today `POST /v1/sessions`
  creates both together so they coincide, but a workspace is what contends for
  disk and daemon, and two sessions sharing one should share one budget. See
  `docs/design/DOCKER-ACCESS.md` §6b for the two cases and why the distinction
  is cheap to honour now and expensive to retrofit.

- **The Docker client, as a seam.** `App\Docker\DockerApi` is the whole of what
  the broker can ask the daemon to do — create from a name and a payload
  somebody else assembled, start, inspect, kill, remove, logs, and the
  workspace volume and session network — with no raw-endpoint escape hatch, so
  the shape of the door is part of the vocabulary. `HttpDockerApi` is the real
  one, and `config/services_test.yaml` swaps in a scripted double: CI has no
  daemon, and the integration tests assert what the broker *asked Docker to
  do* rather than what it reached.

  Failures leave as two typed shapes, deliberately: `DockerUnavailable`
  (nothing was asked; `503`) and `DockerRefused` (asked and told no, in the
  daemon's own words; `502`). A `unix://` `WW_DOCKER_HOST` is refused by name
  before any request leaves — reaching the daemon socket directly is the exact
  thing the proxy exists to prevent.

- **The log reader, for output that must not be trusted to fit.**
  `GET /containers/{id}/logs` on a non-TTY container answers in Docker's
  multiplexed format — `[stream(1), pad(3), size(4 big-endian)]` frames — and
  the reader parses them from a buffer (they split across chunks at any byte),
  splits stdout from stderr, caps each stream, and **stops reading the moment
  a cap is hit**, cancelling the transfer rather than draining output it has
  already decided to discard. A lost connection keeps what arrived and says
  `truncated`; a frame header claiming megabytes is refused rather than
  buffered towards.

- **`POST /v1/sessions`, and the gate in front of it.** Creating a session
  that exists adopts it (`200`) rather than failing — a client retrying after
  a timeout should not have to guess whether the first attempt landed — and a
  fresh one answers `201`. The workspace volume is created here and carries
  the `ww.` labels; `App\Docker\Labels` now owns that vocabulary (the volume
  is its second user; the constants used to live inside `ContainerSpec`).

  Everything under `/v1` requires `Authorization: Bearer $WW_TOKEN`, checked
  **before routing** so an unauthenticated caller gets one answer for every
  path and cannot probe which routes exist. An unset token answers `503`
  rather than allowing through: that is the one condition under which the
  broker would accept anonymous callers while claiming to be the only thing
  holding the Docker credential. `/health` and `/ready` stay open — they touch
  nothing.

- **The broker's request vocabulary, and the one place a container is
  assembled.** `src/Exec/ExecRequest.php` is typed input with construction as
  the authority: an invalid request cannot exist as an object, so the rest of
  the codebase does not re-check it. `src/Docker/ContainerSpec.php` builds the
  `containers/create` payload field by field from that input — which is the
  whole security argument, expressed as a test rather than a paragraph — and
  `src/Session/SessionName.php` is the only source of the resource names a
  session owns, so the `ww-` prefix is generated rather than supplied.

  `cmd` stays an arbitrary **argv array**. That is load-bearing in both
  directions: the container is what is constrained, and a schema that forced
  `"cd x && npm test"` back into a single string would reintroduce shell parsing
  to the one field where an argument could be reinterpreted.

- **The Symfony 8.1 / PHP 8.5 bootstrap**, per the house structure and the
  FrankenPHP base image: composer manifests, config, the canonical vendored
  config leaves, a multi-stage Dockerfile, Caddy config, php.ini and entrypoint.
  Health and readiness are split — `/health` touches nothing, `/ready` reports
  whether Docker is configured at all.

- **PHPStan level 6 with an empty baseline**, php-cs-fixer, PHPUnit and
  `composer audit` — all green, all wired to the shared CI workflow.

- `.env.example` and `.env.test` with every variable documented inline;
  `.env` is never committed.

### Changed

- **`WW_IMAGE` now names the tag that is actually published.** The default was
  `workwarp-base:latest` — a repository that was never built — and `bin/ww-run`
  fell back to `ww-base:latest`, which was never built either. The default is
  now `digitaladapt/work-warp:latest-base` in `.env.example`,
  `config/services.yaml` and `bin/ww-run`, and the tests that pin an image
  name were swept to match.

- **`bin/ww-run` stamps `ww.kind=cmd`, not `ww.kind=command`.** The broker's
  `Labels::KIND_CMD` is `cmd` and the broker owns the vocabulary — the two
  ends of one protocol must not disagree about it. No live container today
  carries the old value; existing labelled resources are unaffected.

- **`Limits` carries the output cap** (`outputByteCap`, 1 MiB per stream). It
  is policy, like every other field there, and the reader stops at it rather
  than draining and discarding. Per stream, not per command: a command that
  fills both streams is two facts.

- **`docker/php.ini` sets `max_execution_time = 0`.** A command may run for
  hours, and the SAPI default of 30 s would turn every long run into a
  truncated request. The bound that matters is the request's own timeout plus
  the kill grace, and `ExecRunner` enforces it.

- **`deploy/compose.yaml` now states what the host actually runs.** The stack was
  rolled out on 2026-10-07 as a working-tree change on the host; the repo copy
  still described the pre-rollout topology (terminal sharing the proxy's network,
  `DOCKER_HOST` pointed at the proxy, `:latest` on the proxy image, the network
  called `lyra-control`). The preserved host diff is now applied: `lyra-terminal`
  is on `public` + `api` with **no `DOCKER_HOST`**, networks are `backend` and
  `api`, and the proxy is pinned to `tecnativa/docker-socket-proxy:v0.5.0` — the
  version every measurement in `deploy/README.md` was taken against.

  `deploy/README.md` was swept to match: the rollout is recorded as executed
  rather than proposed, the two settled decisions (image pin, network rename) are
  marked done, the safeguards table no longer claims `cap_drop: ALL` or
  `no-new-privileges` on the proxy — measured null, and never in the file — and
  the open questions now say which were answered on 2026-10-07. The same sweep
  hit `docs/design/`: §0b/§0e of `DOCKER-ACCESS.md` and §10 of `PLAN.md` now
  record the resolutions instead of asking for them.

### Fixed

- **The broker image could not boot — it had no dotenv file and the runtime
  demanded one.** Found in the 2026-10-12 `develop` build's own log: the
  build-stage smoke died with `PathException: Unable to read "/app/.env"`,
  and a trailing `|| true` kept it from failing the build. The published
  image therefore could not start: `.dockerignore` excludes every dotenv file
  (configuration comes from the environment, §8.12), the Symfony runtime
  reads `/app/.env` unconditionally unless told not to, and the entrypoint's
  `set -e` warmup turned that into an exited container within a second of
  start. Three changes, each verified by the boot matrix below:

  - `ENV APP_RUNTIME_OPTIONS='{"disable_dotenv":true}'` in the runtime stage
    (and on the build-stage smoke), the same line task-loom's images carry.
    Both of this image's SAPIs see it: the CLI merges the environment into
    `$_SERVER`, and FrankenPHP exposes it there by contract — verified by
    running the image's own FrankenPHP version (v1.13.0, PHP 8.5.11) in a
    container-shaped boot and reading `$_SERVER`/`$_ENV` in a served script.
  - `env(DEFAULT_URI): 'http://localhost'` as a `services.yaml` parameter
    default — the second, quieter half: with dotenv off, the compile step
    stopped on `Environment variable not found: "DEFAULT_URI"`, which
    `routing.yaml` reads with no fallback. Same pattern as penny-track and
    vital-pulse.
  - The smoke's `|| true` is gone. It was written to smoke-test the
    autoloader and config compile and could never fail at either — it is
    exactly how this shipped. `BrokerImageTest` now holds all three: the
    switch is set, no smoke line can swallow its exit code, and the boot
    actually succeeds in a subprocess with dotenv disabled and no `.env`
    reachable.

  The boot matrix, run against a checkout with `.env` moved aside (a fresh
  image's shape): dotenv on, no file → `PathException` (rc 255); dotenv off →
  `DEFAULT_URI` missing (rc 1); dotenv off with the parameter default and
  `APP_SECRET` supplied → **cache warmed, rc 0**.

- **The command image's default `HOME` now points somewhere writable.** The
  smoke test caught it: "the image must not set HOME" was only half a rule,
  because the runtime sets one anyway. runc derives `HOME` from the
  container's passwd entry whenever the environment carries none (v1.1–v1.2 in
  `libcontainer/init_linux.go`; v1.3+ in `libcontainer/env.go`; `/` when the
  lookup fails), and the entry named `/home/user` — a read-only path under the
  production shape. Every `$HOME` write therefore died on the read-only
  rootfs: `git config --global`, ssh's `known_hosts`, pip's cache. The passwd
  entry now names `/workspace`, the one directory a command can write and the
  one whose contents persist in the volume. The image still sets no `HOME` of
  its own — the broker passes the environment per request — and a
  caller-supplied `HOME` still wins over the derived default.

  `ww-base/smoke.sh` now checks the real contract instead of the unachievable
  one: the image config carries no `HOME`, the runtime-derived one is
  `/workspace` (asserted in both run shapes and by writing to it under
  `--read-only`), and a caller-supplied `HOME` survives. The Dockerfile's third
  load-bearing property and the README's "a session should know" section are
  rewritten to match.

- `Slots` exceeded its own capacity on the first run. A `Lock` is re-entrant
  with respect to itself — a second `acquire()` on the same object succeeds — so
  handing out one cached `Lock` per slot issued three reservations from a pool
  of two, while the cross-process behaviour was correct the whole time. Each
  reservation now takes a fresh object, so the same-process case goes through
  exactly the same conflict path a foreign process does.

- A wait deadline built with `DateTimeImmutable::modify('+1.000 seconds')` never
  arrived: PHP's relative date format accepts fractional units and silently
  ignores them, so `reserve(wait: 5.0)` refused instantly instead of waiting.
  Deadline arithmetic is on floats now, and the clock fake advances by epoch
  seconds for the same reason.

- `SessionName`'s pattern, the environment-key pattern and the numeric-timeout
  check all anchored with `$`, which in PCRE also matches before a trailing
  newline — so `"refactor\n"` was accepted as a session name and would have
  produced a resource name with a newline in it. Now anchored with `\z`. Found
  by the test that lists the hostile names a name must not smuggle through.

- The **Tests workflow could not boot at all**: `composer install`'s
  post-install `cache:clear` died with `Environment variable not found:
  "DEFAULT_URI"`. The shared workflow copies `.env.test` over `.env`, and
  `.env.test` did not define the variable that `config/packages/routing.yaml`
  reads with no fallback — only the untracked local `.env` had it, so the boot
  passed on a developer machine and failed on every CI run. `DEFAULT_URI` now
  lives in `.env.test`, and a new `CommittedEnvironmentTest` fails whenever a
  `%env()` reference without a fallback is added to `config/` without a
  definition in the committed test environment. The unused `.env.ci` is gone
  with it: its header described a copy step the shared workflow does not have,
  nothing read the file, and a second env file that looks load-bearing but is
  not is worse than none.

- `composer lint` ran `php-cs-fixer fix -d`, and `-d` is not an option — the
  contributor gate documented in CONTRIBUTING.md failed before it could check
  anything. It now matches the house script: `--dry-run --diff`.

### Notes

- `WW_MAX_JOBS` from `bin/ww-run` becomes `WW_MAX_CONCURRENCY`, enforced with
  `symfony/lock` rather than counted in a variable, so the semaphore survives a
  restart and cannot drift after a crash.
- The socket proxy is retained *behind* the broker as defence-in-depth. It
  cannot scope by label, filter a request body, or hold a clock; the broker can
  do all three, which is why the credential moved.
