# WorkWarp — Plan

**WorkWarp: the persistent surface that ephemeral commands cross.**

A workspace, terminal and filesystem for long-running agent tasks: work that runs
for days or weeks toward a single large goal, where each individual command is
short-lived, disposable and untrusted.

This document is the plan, not the implementation. It records the decisions we
have made, the evidence behind them, and the questions still open. Where a claim
was verified against a running system it says so; where it is an assumption it
says that instead.

---

## 1. Why the name

The harness family is named after weaving, and every name is
`<substance being handled> + <weaving tool>`:

| Component | Substance | Tool | Role |
|---|---|---|---|
| TaskLoom | Task | Loom | The harness. Weaves tasks into runs. |
| ContextShuttle | Context | Shuttle | MCP server. Carries context across the gap. |
| MemoryDraft | Memory | Draft | API memory management. Draws out the fibre. |
| **WorkWarp** | **Work** | **Warp** | **The persistent surface.** |

The warp is the long, strong set of threads on a loom, held under tension, that
everything else works across. Weft, shuttle and heddle all exist in relation to
it. That is the role this component plays: durable and stretched over time,
crossed once by each ephemeral command and then left alone.

Bare `warp` was rejected on collision grounds, not taste: there is already a
`warp` terminal product, Cloudflare WARP, and a Rust web framework of the same
name. `work-warp` also breaks the convention less than it appears to — the
family's own `memory-draft` uses `-draft` as a craft noun rather than as a
literal second tool name.

Container prefix: **`ww-`**.

---

## 2. The problem

The current terminal tool is `open-terminal`. It works, and the survey below is
what has to be fixed rather than a reason to throw it away. All findings were
observed directly on the running instance (2026-10-07).

### 2.1 The shell shares an identity with the harness

| Property | Observed value |
|---|---|
| Identity | `uid=1000(user)` — the same UID as pid 1 |
| Namespaces | own pid/mnt/net/uts/ipc/cgroup; **user ns is the host's** |
| Capabilities | `CapEff=0`; seccomp filter active; Docker defaults |
| Pid 1 | `/usr/bin/tini -- /app/entrypoint.sh run` |

Because the shell and the server run as the same UID, `/proc` is a single
security domain and every dumpable process is readable by every other one. This
is the root cause of everything in §2.2, and it is not fixable by scrubbing
environment variables.

### 2.2 The harness's own secrets are exposed

- `/proc/1/environ` is readable (mode `0400`, owned by `user` — and the shell
  *is* `user`). Its variables include `OPEN_TERMINAL_API_KEY`.
- The runner passes its own environment into every command it spawns, so
  `OPEN_TERMINAL_API_KEY` is also in the environment of every command the agent
  runs.
- The MCP/LLM stub servers carry five secret-named variables each.
- `~/.ssh/id_ed25519` — the private key — is present and readable.

The stated governing principle is that the agent must never be able to see or
know any secret or key while still being able to run commands that need them.
The current design cannot satisfy that principle, and no amount of scrubbing
will change it while the shell shares a UID with the harness.

### 2.3 The shell cannot run several commands at once

Four parallel invocations run concurrently, and pipelines work. The failure is
narrower and more specific than "one at a time":

- The output channel is a PTY (`stdout: TTY`), so commands believe they are
  interactive.
- `git var GIT_PAGER` resolves to `pager` → `less`, which launches a pager that
  waits forever for keystrokes that never arrive.
- Three `/usr/bin/pager` processes from **three days earlier** were still alive
  and still holding PTYs. There is no process-tree reaping and no timeout.

Leaked sessions accumulate against whatever session limit the tool has, which is
why the *fourth* concurrent command is the one that appears to fail.

Consequences beyond the limit: ANSI colour codes land in the model's context,
and a `TTY` stdin means anything that reads stdin blocks forever.

### 2.4 Toolchain changes are made with `sudo`, in the pet container

The current workspace is a single long-lived container. Adding a PHP extension
means installing it by hand, which works, but leaves the container as an
unreproducible snowflake that cannot be rebuilt from source. This is treated as
a feature (§6.3 explains why it is not).

---

## 3. The identity model

Every other tool in the harness is capability-bounded by construction: the
calendar tool cannot touch mail; the mail tool cannot run code. A shell is the
first tool whose capability set is "whatever the process can do". So the design
question is not *how do we sandbox a shell* but **what identity does the shell
have, and what authority does that identity carry?**

The answer here:

1. **The workspace is not an identity.** It is a directory. Two processes work
   in the same directory without being the same principal.
2. **The command is the identity.** Each command runs in its own container, with
   its own UID, its own mount namespace, its own network namespace, and no
   ambient credentials.
3. **The session is a policy**, not a process. It is the collection of rules
   under which commands are launched, and the ledger they write to.
4. **Credentials never reach the shell.** Where a command genuinely needs a
   credential, it is injected into *that* container, at the last moment, in
   memory, scoped to that command — never into the environment of the harness or
   of the session. This is the mechanism that makes the governing principle from
   §2.2 satisfiable at all.

The last point is the one that makes Docker access possible without breaking the
security model. The shell never holds a docker socket; it asks a broker to run
something.

---

## 4. Architecture

Three layers, with one rule: **state lives in the volume, never in a process.**

```
┌─────────────────────────────────────────────────────────────────┐
│ SESSION  (durable, policy + identity, may live months)           │
│                                                                  │
│   session id · policy (TTLs, limits, mounts) · ledger            │
└───────────────────────────┬─────────────────────────────────────┘
                            │ launches
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│ WORKSPACE  (persistent named volume)                             │
│                                                                  │
│   <workspace>/                                                   │
│     work/      the working tree — git repos, the actual code     │
│     ledger/    timestamped progress notes (see §6.2)             │
│     out/       artifacts a command produced and wants to keep    │
└───────────────────────────┬─────────────────────────────────────┘
                            │ mounted into
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│ COMMAND  (ephemeral container, seconds to minutes)               │
│                                                                  │
│   base image + workspace volume + one command → output → gone    │
│   own UID · own netns · own mountns · no ambient credentials     │
└─────────────────────────────────────────────────────────────────┘
```

**A session that lasts months cannot be a live process.** The three-day-old
pagers in §2.3 are the proof: long-lived processes leak. The durable objects are
the volume and the ledger; every command invocation is a fresh, reaped container
that shares only the volume.

---

## 5. The ephemeral command container

### 5.1 Non-negotiable properties

- `--init` (reap orphans), `--rm` (no corpses), non-root user inside.
- Own network namespace. No access to the harness's container or its `/proc`.
- **No docker socket.** Docker is reached through the broker (§7), never mounted.
- Output capped before it reaches a context window.
- A timeout enforced by the *supervisor*, not by the command, and enforced with
  `killpg` on the whole process group — not `SIGTERM` to the direct child only.
- `PAGER=cat GIT_PAGER=cat TERM=dumb NO_COLOR=1 CI=1` set on every command. This
  alone fixes the pager failure in §2.3, and it is worth doing today, before any
  of the rest exists.

The last item is the direct fix for the observed failure. A terminal is a device
for a human; a captured output channel is not a terminal, and should never claim
to be one.

### 5.2 Mounting the workspace

Mount the volume at **`/workspace`**, not `/app`. Bind-mounting over a directory
the image already populated is the classic "why is my container empty" trap, and
the base image's own content should stay where it is.

---

## 6. The workspace

### 6.1 Image plus volume: environment and code are separate knobs

The dilemma that produced the current pet container — *"if the workspace is
short-lived, my ability to add a PHP extension vanishes"* — dissolves once
"what is installed" and "what the code is" are separated.

- **Environment → the image.** sysadmin-owned, shared, cached, reproducible.
  Adding `intl` is one line in the Dockerfile. Because the extension line sits
  *after* the dependency layers in the cache chain, only that line rebuilds:
  seconds, not minutes.
- **Working tree → the volume.** Session-owned. The only thing that persists.
- **Command → a fresh container.** Nothing survives the command, so stuck
  processes become structurally impossible rather than something to clean up.

**The safety valve is the ability to build an image, not `sudo`.** "We need the
INTL extension" is a Dockerfile line and a cheap rebuild. If a session genuinely
needs something ad hoc mid-flight, it may derive a private tag
(`workwarp-base:<session>`) that it is free to abuse. The shared base stays
clean, the session keeps its flexibility, and the snowflake is disposable by
construction.

### 6.2 The ledger

A timestamped, append-only progress record inside the workspace
(`ledger/YYYY-MM-DD.md`). A session running for weeks cannot rely on a context
window to remember where it was; it has to be able to read its own history from
the volume. Every long-running session writes to it, and the janitor writes its
kills there too (§8), because reaping the agent cannot observe turns a clean
system into a confusing one.

### 6.3 Multiple workspaces

Supported, and cheap, because a workspace is only a named volume plus a label.
It costs one extra field in the session record and one in the API. Several
workspaces are the natural shape when one session works on two repos, or when a
finished task's workspace should be kept around read-only while the next begins.

The complexity to resist is *not* multiple workspaces — it is workspace
*migration*, *nesting*, and *cross-workspace mounts*. One command, one workspace,
always.

---

## 7. Docker access

The target: when the agent is told "test this project", it can build the real
image, start the real stack, run the real end-to-end test, and tear it down.
That is the single biggest quality-of-life improvement available, because it
closes a gap that currently cannot be closed any other way (§9).

### 7.1 Reaching the daemon

Three routes were considered:

1. **Proxy a daemon on the host or a dedicated VM** — chosen.
2. **Nested dockerd inside a user namespace** — rejected.
3. **No nesting at all** — subsumed by route 1.

Route 2 was rejected on a concrete, verified blocker. Docker's default seccomp
profile gates `unshare` on `CAP_SYS_ADMIN`, specifically to prevent unprivileged
user-namespace creation:

```
max_user_namespaces: 95272      ← the sysctl allows it
unshare -U → Operation not permitted
```

That single fact rules out **every** rootless option — rootless podman, rootless
dockerd, `bwrap`, `nsjail` — and the outer container's seccomp profile is
deployment-side, not something a session can change.

```
                    ┌─────────────────────────────────┐
   session ─────────┤ broker (guardrails)             │
   (no socket)      │  · injects labels + TTLs        │
                    │  · namespaces via -p            │
                    │  · allowlists endpoints         │
                    │  · keeps harness out of reach   │
                    └───────────────┬─────────────────┘
                                    │ docker API
                                    ▼
                    ┌─────────────────────────────────┐
                    │ dockerd on the HOST or a VM     │
                    │  ← THE WALL. Flag abuse lands   │
                    │    in a VM, not on the host.    │
                    └─────────────────────────────────┘
```

### 7.2 The division of labour, stated honestly

An earlier draft of this design listed "no `--privileged`, no `--pid=host`" as
things the socket proxy delivers. **It does not.** docker-socket-proxy filters by
*endpoint*, not by request body: allow `/containers/create` and a
`--privileged --pid=host` payload goes straight through. Closing that requires a
body-inspecting shim in front of create.

So the layers do different jobs, and neither substitutes for the other:

- **The VM is the wall.** This is route 1's entire value. Flag abuse lands inside
  a VM, not on the host. This is the layer that actually stops a determined agent.
- **The broker is guardrails.** It stops accidents, namespaces resources,
  injects labels and TTLs, and keeps the session's own harness container out of
  reach. Convenience and blast-radius reduction — **not** a security boundary.

If the daemon is on the developer's own host, the broker is the only thing
between an agent and host root, and endpoint filtering is not strong enough for
that job. Route 1 with a host daemon is a meaningful improvement over today, but
it is not categorically safe. The categorically safe version is a VM per session
(gVisor, or an Incus / Firecracker microVM), where the guest kernel is a separate
kernel and container escapes cannot reach host root. That is a deployment change.

**Corrected 2026-10-07, and the correction favours the broker.** The paragraph
above assumed the session could reach the proxy and that only the VM could close
that. It can be closed without a VM: the session and the proxy share
`lyra-control`, so put the broker in between and take the session *off* that
network. Docker access then stops being "filtered" and becomes *unreachable* —
the agent cannot call the API at all, and the one service it can call cannot
express `--privileged`. The VM still matters, for the threats the broker cannot
touch (a container-escape CVE, or a bug in the broker itself); it is no longer
the only thing standing between an agent and host root. See
`DOCKER-ACCESS.md` §1 and §4.

**`--privileged` nested dockerd is host root and this design does not use it.**
The escape is to bind-mount the host rootfs, and any containment built inside is
defeated by the privilege granted to get there.

### 7.3 Naming and ownership

Two mechanisms, doing different jobs:

- **Labels are authoritative.** `ww.session`, `ww.task`, `ww.ttl`, `ww.kind`.
  The janitor queries these. Ownership must never be inferred from a name
  string.
- **The `ww-` prefix is for humans.** Six characters that survive `docker ps`
  column truncation and declare whose mess it is at 2am.

For anything Compose creates, use the **project name, not per-container names**:

```
docker compose -p ww-<session> up -d --wait
```

This prefixes every resource Compose creates — containers, networks, volumes —
and gives a single complete teardown:

```
docker compose -p ww-<session> down -v
```

Forcing `container_name:` prefixes across a stack means fighting Compose on
every service. Namespace via `-p`, declare ownership via labels, skip the name
surgery.

---

## 8. The janitor

A periodic sweep that stops containers which have outlived their useful life.

**Two ages, not one.** "Half an hour" is right for a test server and wrong for a
build, so the TTL is a label decided at create time:

| Condition | Action |
|---|---|
| Running longer than `ww.ttl` | `SIGTERM`, honour `--stop-timeout`, then `SIGKILL` |
| Exited and forgotten | `docker rm -v` |

The second matters more than it sounds: it is the **anonymous volumes and
dangling images** that fill a disk, not the running containers.

Three complements, because a five-minute sweep has a five-minute blind spot:

- `--rm` on one-shots, so there is nothing to leak in the first place.
- `--stop-timeout` and health checks, so "running" means something.
- A **self-terminating watchdog** inside long-lived servers: the container shuts
  itself down at its TTL. The janitor is the brace, not the belt.

**The janitor is bookkeeping, not security.** The session can label whatever it
likes, or strip its label, because it owns the access it was given. If ownership
must be *enforced*, it has to be enforced at the broker — reject creates that
lack a label, and inject the TTL there rather than trusting the caller.
Convention for legibility; enforcement at the chokepoint.

**Janitor kills are written to the ledger** (§6.2).

---

## 9. What this buys: the task-loom example

TaskLoom is the worked example of the gap. `docker/Caddyfile` contains:

```
handle /assets/* {
    header Cache-Control "public, max-age=31536000, immutable"
}
```

and the Dockerfile's build stage runs `asset-map:compile` to produce hashed
importmap filenames. So "assets work" is a fact about **Caddy plus the build
stage**, not about the PHP code. Run the project by hand — `symfony serve`, or
`php -S 127.0.0.1:8000 -t public/` as the README suggests — and you are testing a
different system than the one you deploy. No amount of local PHP testing closes
that gap; only the real image can.

With Docker access, the loop is:

```
docker compose -p ww-<session> up -d --wait      # migrate → app, real Caddy
curl -sI localhost:<ephemeral>/assets/<hashed>   # the real headers
docker compose -p ww-<session> logs              # captured as an artifact
docker compose -p ww-<session> down -v
```

**Two landmines to fix while implementing this:**

1. `compose.yaml` publishes `8080:80` on a fixed host port. Two concurrent
   sessions and the second `up` fails on a port conflict — the "can't run two
   things at once" complaint in its next incarnation. Use Docker-assigned
   ephemeral ports and read them back (`docker port`), or reach the service by
   name from a sibling container on the project network. Never hardcode it.
2. `.dockerignore` line 20, `/importmap.php/config/reference.php`, is a missing
   newline. As written it ignores a path that does not exist, so both
   `importmap.php` and `config/reference.php` ship in the build context. Low
   stakes; not doing what it says.

---

## 10. Deployment requirements (outside this repo)

Three of these cannot be done from inside the sandbox and are blockers:

1. **A dockerd on the host or a dedicated VM.** None is currently reachable:
   Docker client 29.8.0 is installed, but there is no `docker.sock` and
   `DOCKER_HOST` is unset — and since the 2026-10-07 rollout that is *by design*:
   the session was taken off the proxy's network and its `DOCKER_HOST` removed,
   so the broker has a real path to occupy rather than a route to share
   (DOCKER-ACCESS.md §4, §10d).
2. **A workspace volume that is not the developer's home directory.** Re-measured
   2026-10-08: `/dev/nvme0n1p2` is 221G at **55% used, 95G free** (it was 22G free
   at 90% on 2026-10-07, before the host was cleared). Better, and still not a
   quota: a months-long session with Docker images needs its own volume with a
   limit, because "enough free space today" is not a policy.
3. **A decision on the wall.** Settled 2026-10-07: broker to the **host daemon**,
   keeping the socket proxy behind it. The broker's API cannot express
   `--privileged` and the API is unreachable from the session, so the agent-side
   threat closes without a VM; the VM's remaining job is kernel escapes and
   broker bugs, and is deferred until this runs somewhere other than one
   developer's host (§7.2).

---

## 11. Open questions

- Where does the workspace volume live, and what is its quota policy?
- Does the workspace image build on the host (slower, no host toolchain
  dependency but a build daemon per session) or in the command container
  (faster, needs docker in the build)?
- Does the janitor run as a host-side daemon, or as a sidecar in the broker?
- Does the session record live in this repo's own store, or in MemoryDraft?
- Should the ephemeral container be `--network none` for commands that do not
  need the network, and only granted a netns when they do?

---

## 12. Build order

Each phase is useful on its own, and the first two are worth doing before any of
the rest is decided.

### Phase 0 — fix the current tool (independent of everything here)

- The command wrapper: `PAGER=cat GIT_PAGER=cat TERM=dumb NO_COLOR=1 CI=1`, plus
  `setsid` and `killpg` on timeout. This fixes the observed pager failure today.
- Reap the three stale pagers.

### Phase 1 — the ledger and the session record

The ledger is the cheapest durable thing, and it is what makes multi-week
sessions possible. No Docker needed.

### Phase 2 — the command container

Ephemeral container per command, workspace volume mounted at `/workspace`,
`--init`, capped output, supervised timeout. Stuck processes become impossible.

### Phase 3 — the broker and the janitor

Endpoint allowlist, label/TTL injection, `down -v` teardown, two-TTL sweep, kills
written to the ledger. Requires the daemon from §10.

### Phase 4 — the compose test harness

Ephemeral ports, `--wait`, logs captured as an artifact, `down -v` always. Then
the task-loom e2e in §9 becomes a normal operation rather than a manual one.

---

## 13. Non-goals

- **Not a container orchestration platform.** Kubernetes, Swarm and multi-node
  scheduling are out of scope; one daemon, one session.
- **Not a development-environment manager.** The workspace image is a means to
  run commands, not a promise to reproduce anyone's laptop.
- **Not a secret store.** Credentials are injected per command (§3.4); WorkWarp
  does not hold, index or hand out secrets.
- **Not a replacement for ContextShuttle.** WorkWarp owns the filesystem,
  terminal and process layers. Calendars, mail and the domain tools stay where
  they are.

---

## 14. Decision log

| Date | Decision |
|---|---|
| 2026-10-07 | Named `work-warp`; container prefix `ww-`. Bare `warp` rejected on collision. |
| 2026-10-07 | Route 1: broker to a host/VM daemon. Nested dockerd rejected (`unshare` gated on `CAP_SYS_ADMIN` by the default seccomp profile). |
| 2026-10-07 | Every created container carries a `ww-` prefix and `ww.*` labels. |
| 2026-10-07 | A periodic janitor stops containers past their TTL, with two TTLs (running / exited) and kills written to the ledger. |
| 2026-10-07 | Workspace = immutable base image + persistent volume. Disposable container per command. No pet container. |
| 2026-10-07 | Multiple workspaces supported; migration, nesting and cross-workspace mounts are not. |
| 2026-10-07 | The VM is the security wall; the broker is guardrails, not a boundary. |
| 2026-10-07 | **Superseded in part** by measurement: the broker is built and holds the credential, and label-scoped ownership is *enforcement*, not guardrails. The VM's remaining job is escapes and broker bugs, not API access. |
| 2026-10-07 | The session leaves `lyra-control`; broker gets its own network between terminal and proxy. Measured: they share `lyra-control` today, so the proxy's policy was advisory. |
| 2026-10-07 | The socket proxy is retained as defence-in-depth *behind* the broker, not as the boundary. It cannot scope by label, filter bodies, or hold a clock. |
| 2026-10-07 | The broker's API is designed so dangerous operations are unexpressible rather than refused: no field exists for privileged, mounts, caps, devices or arbitrary names. `cmd` stays arbitrary; the container is what is constrained. |
| 2026-10-07 | `build` is an intended capability, so the broker keeps a scratch build path (session tag, disposable, unpushable) rather than blocking the endpoint. |
| 2026-10-07 | The MCP surface is built last, over a settled broker API. |
| 2026-10-08 | Source lives in `src/` in this repo, not a second repo: the broker and `ww-run` are two ends of one protocol, and it is one application. |
| 2026-10-08 | The exec cap is per **workspace**, not per session — the workspace is what contends. Today one session creates one workspace, so they coincide; they stop coinciding when a session may name an existing workspace. Enforced as a pool of file locks (N slots), because `FlockStore` is a mutex and a counter is a lie after a crash. |
| 2026-10-08 | `deploy/compose.yaml` is reconciled with the running stack: the preserved host-side rollout diff is applied (terminal off the proxy network with `DOCKER_HOST` removed, networks `backend`/`api`, proxy pinned to `v0.5.0`). The host is the fact; the file now agrees with it. |
