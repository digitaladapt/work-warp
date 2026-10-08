# WorkWarp — Docker access, milestone 1

Companion to `PLAN.md`, narrowed to one question: **what component should hold the
Docker credential, and what should the session be allowed to say to it?**

Written 2026-10-07, after the proxy went up and was measured
(`deploy/README.md` §"Measured"). Everything asserted here is either measured or
explicitly labelled as untested.

---

## 1. The answer, up front

**The socket proxy cannot be the boundary, and the reason is not its policy
granularity.** It is that the session can reach the proxy directly. From
`deploy/compose.yaml`, measured (the *pre-rollout* file — it has since gone
through §10b, so the network names in the snippet below are historical):

```
lyra-terminal   networks: [public, lyra-control]
lyra-dockerproxy networks: [lyra-control]        # port 2375
```

Same network. So `curl http://lyra-dockerproxy:2375/...` works from inside the
session environment — which is exactly how every check in the PR was run. The
proxy is a *convenience*, not a wall: it filters what a well-behaved client asks
for, and the Docker API is reachable regardless of what it permits.

So we are not choosing between "keep tecnativa" and "write our own proxy".
We are choosing where the credential lives. Three reasons a proxy can never be
the boundary, in order of how much they hurt:

1. **It cannot scope by label.** The requirement is "may not touch other existing
   containers or volumes". That is a property of the *target*, not the path.
   `tecnativa/docker-socket-proxy` has no way to express it: `DELETE
   /containers/nextcloud-db` and `DELETE /containers/ww-s-abc-123` are the same
   request to it.
2. **It filters path, not body.** Measured: `POST /containers/create` returns
   `400` (schema error) rather than `403` — i.e. it is forwarded. A
   `--privileged --pid=host` payload is the same request as a bounded one.
3. **It has no clock.** Nothing in it can stop a container that has run too long.
   The TTL requirement needs a component that *watches*.

> **Recommendation: build the broker.** It is small — it is not a Docker API
> proxy, it is a ~10-endpoint HTTP service. And keep the socket proxy, moved to
> the far side of the broker, as defence-in-depth for the broker's own blast
> radius. Two walls of different heights, neither pretending to be the other.

---

## 2. The design principle: unexpressible beats filtered

A filter can be wrong, misconfigured, or bypassed by a request nobody
anticipated. **An absence cannot.** So the broker's API is designed so that the
dangerous operations are not *refused* — they are **not expressible**.

There is no field in which to put `--privileged`. No field for a bind mount, a
device, a capability, `--pid=host`, a host path, an arbitrary container name, an
arbitrary network, or a label. Not a denylist: a vocabulary that lacks the words.

A second principle falls out of it, and it is the one that makes "arbitrary
command" and "limited access" compatible: **constrain the container, not the
command.** The `cmd` field *is* arbitrary, deliberately — that is what the
product is for. What is fixed is everything the container is and can reach. An
arbitrary command inside a container with no capabilities, no network, a
read-only rootfs and one mount can still only do arbitrary things *to its own
workspace*.

---

## 3. What every command container gets

These are not the broker's refusals; they are the only shape it can create.
Every line is `ww-run`'s current list, plus the measured findings.

| Property | Why |
|---|---|
| `--init` | PID 1 reaps. Without it, a command that forks orphans zombies forever. |
| `--cap-drop=ALL` | No capability is needed to run a build or a test. |
| `--security-opt=no-new-privileges` | Setuid binaries inside the image cannot gain anything. |
| `--pids-limit` | Fork bombs die at the limit instead of taking the host with them. |
| `--memory`, `--cpus` | The host also runs the calendar, the memory service and a dozen others. |
| `--network none` (default) | Reaching anything is opt-in per command, with two allowed values. |
| `--read-only` + `--tmpfs /tmp` | **New.** Nothing persists except the workspace mount. Removes "write to `/` and leave it". |
| exactly one `-v`: the session's volume at `/workspace` | The mount list is not a parameter. |
| `--label ww.created-by=work-warp`, `ww.session`, `ww.ttl` | Ownership from a label, never from a typed name. |
| name `ww-<session>-<id>` | The prefix is generated, not supplied. |
| one env var: `WW_SESSION` | **Don't inherit the daemon's or broker's environment.** |
| `--stop-timeout` | Bounded shutdown, so `kill` cannot hang on a wedged process. |

Two of these are additions to the current `ww-run`: **`--read-only`** and **not
inheriting the environment**. The second matters more than it looks — it is the
same class of bug as the `/proc/1/environ` leak that started this project, one
layer up.

---

## 4. Network topology — the actual change

**Half-landed.** The terminal half is done (2026-10-07): `lyra-terminal` is on
`public` + `lyra_api` and off `lyra_backend`, with `DOCKER_HOST` removed. The
broker half is not: `ww-broker` does not exist yet, so nothing on `lyra_backend`
reaches `lyra_api` and the proxy currently has no client at all. The diagram is
the target shape, and it is the shape the current file is written for.

The point is not a new network. It is that **the session must not share a
network with the proxy.** One hop, one narrow surface:

```
 public (32 members)      lyra_api (new, 2)      lyra_backend (2)
 ───────────────────      ─────────────────      ────────────────
 lyra-webui               ww-broker ──────────────▶ lyra-dockerproxy
 task-loom                    ▲                     :2375
 context-shuttle              │                          │
 memory-draft                 │                          ▼
 gitea, … (28 more)           │                    /var/run/docker.sock
 lyra-terminal ───────────────┘                          │
 (removed from lyra_backend)                             ▼
                                                    dockerd (host)
```

Measured 2026-10-07, and it makes the change **subtractive**: `lyra_backend`
today contains exactly two containers — `lyra-dockerproxy` and `lyra-terminal`.
That is already the shape this design wants; the work is deleting one membership,
not inventing a topology.

- `lyra-terminal`: `public` + **`lyra_api`** — and *removed from* `lyra_backend`.
- `ww-broker`: `lyra_api` + `lyra_backend`.
- `lyra-dockerproxy`: `lyra_backend` alone.

**Naming drift, resolved 2026-10-08.** `deploy/compose.yaml` used to call this
network `lyra-control`; the live host calls it `lyra_backend`. The file was
edited to agree with reality (declaring `backend` and `api`, which resolve to
`lyra_backend` and `lyra_api` under project name `lyra`), because renaming a
live network touches running containers for no security gain. The applied file
is preserved in the repo as the fact, per `deploy/README.md`.

A new `lyra_api` (broker + terminal, nothing else) rather than putting the broker
on `public`: 32 containers sit on `public` — immich, jellyfin, vaultwarden,
nextcloud, gitea, task-loom and the rest — and every one of them would otherwise
be able to reach the broker. The broker is a new attack surface and it should be
visible to exactly one client.

A new `lyra-api` (broker + terminal, nothing else) rather than putting the broker
on `public`: every service on `public` — jellyfin, immich, vaultwarden,
nextcloud, gitea and the rest — would otherwise be able to reach the broker. The
broker is a new attack surface and it should be visible to exactly one client.

**Removing `lyra_backend` from `lyra-terminal` is the single most important line
in this document.** Without it, the broker is advisory, because the API is still
one `curl` away.

**But it is necessary and not sufficient, and the probe proved how the two halves
fail apart.** `POST /networks/{id}/connect` is **forwarded** through the proxy
(`400`, not `403`) — so a session that still has a route to the proxy can re-add
itself to `lyra_backend` in one request:

```
POST /networks/{id}/connect     -> 400  FORWARDED   ← the session can re-attach itself
POST /networks/create           -> 400  FORWARDED
```

So the precise claim is not "unreachable therefore safe". It is:

1. The network removal closes the path — *provided the session has no other
   route*. It works because the re-attach request needs the very access being
   removed. That is load-bearing, and it is why the removal must be complete
   rather than almost-complete.
2. Which means a **half-migration is silently equivalent to no migration**: one
   forgotten network, one leftover route, and the session reconnects itself and
   nothing anywhere reports a problem.
3. Therefore the credential is the backstop. The session should hold **no token
   the proxy accepts** — it authenticates to the broker, and only the broker
   authenticates to Docker. Then a network mistake is survivable: an exposed
   proxy still refuses a caller with nothing to present.

Defence in depth here is not belt-and-braces, it is two *different* failure
modes: the network fails closed on access, the credential fails closed on
authorisation, and neither is trusted to cover the other's mistake.

> The topology (subtractive, two-container `lyra_backend`) is measured. The
> reconnect result is measured. What was **untested** was the end state: that a
> terminal on `lyra_api` cannot reach the proxy by any route not considered.
> **Now measured** — see §10d. The session has no route to the proxy and no API
> on either of its own bridges, against a working DNS path to five neighbours.

**What this buys, and it is more than I previously claimed.** I said a VM was
required, because endpoint filtering is not strong enough to hold host access.
With the API unreachable from the session, the agent-side threat closes *by
construction*: a hostile command in the terminal can reach a service that cannot
express `--privileged`, and cannot reach the one that could. The VM still matters
for the *other* threats — a container-escape CVE, or a bug in the broker itself.
The VM defends the kernel; the broker defends the API. They are not substitutes.

---

## 5. The broker's API

Complete surface. In the paths below, `exec`/`build`/`ps` exist so there is
nothing to reach past them for.

```
POST   /v1/sessions                 {name}            → {session, workspace}
GET    /v1/sessions                                    → [{session, ...}]
GET    /v1/sessions/{name}                             → {session, containers, ledger}
DELETE /v1/sessions/{name}          {keep_workspace?}  → destroys containers + volume
POST   /v1/sessions/{name}/exec     {cmd[], timeout, network, stdin?}
                                                       → {exit_code, stdout, stderr, truncated, ...}
POST   /v1/sessions/{name}/build    {context: tar, tag, network}
                                                       → {image}   (scratch, disposable)
GET    /v1/sessions/{name}/ps                          → ww- containers for this session only
POST   /v1/reap                                        → janitor: stop past-TTL, report
GET    /healthz
```

Bodies are typed request objects, not scalar maps — see §5a for why, and for the
full field table. Path parameters and `name`/`tag` are bounded:

| Field | Permitted | Notes |
|---|---|---|
| `name` | `[a-z0-9][a-z0-9-]{0,30}` | becomes `ww-ws-<name>`; validated, never interpolated |
| `cmd` | array of strings | **arbitrary** — that is the point |
| `timeout` | 1..14400 seconds, default 1800 | the broker kills at expiry |
| `network` | `none` \| `session` | an enum. Nothing else means anything. |
| `workdir` | string, inside the workspace | refused, not normalised, if it escapes |
| `env` | map<string,string> | the *container's* env, declared explicitly, never inherited |
| `stdin` | string, capped | optional |
| `tag` | `[a-z0-9._-]{1,40}` | becomes `ww-<session>-<tag>` |

There is deliberately no `mount`, `privileged`, `cap_add`, `user`, `entrypoint`,
`hostname`, `label` or `name`-beyond-the-tag. `network: session` is the only thing
that grants any reach, and it points at a network the broker created and named.
`mount` is the one omission that is a *deferral* rather than a statement of
impossibility — it needs its own gate, and that is a different decision from being
unable to express it.

Two design consequences to write down now, because they are cheap here and
expensive later:

- **`--network none` at build time breaks most real builds.** A `Dockerfile`
  with `RUN npm install` needs egress. So build defaults to `none` and callers
  pass `session` when they must — and the *result* is still a disposable image.
  Getting this backwards makes the feature useless and then gets it disabled.
- **Streaming.** `exec` as written buffers stdout and returns it. For a command
  that runs for hours that is wrong — the broker becomes a memory leak and the
  caller sees nothing until the end. Milestone 1 can buffer; the API should be
  chunked (`Transfer-Encoding: chunked`) or return a handle to poll, and that
  choice decides whether the broker can be in the path of a 6-hour test.

---

## 5a. The API shape: a typed object, and what we give back to ContextShuttle

**Decision: WorkWarp's `exec` takes a typed object, and WorkWarp does not inherit
ContextShuttle's current schema limitation.** Andrew's framing is right and mine was
backwards. ContextShuttle was cited for the MCP plumbing — the `McpServerFactory`,
the YAML-to-tool registration, the gate idiom — not as a ceiling on what this API
may express.

First, a correction to my own last message: I said ContextShuttle's schema is
"scalar only". That overstated it, and the measured truth is more useful.
`ToolDefinition::inputSchema()` does not restrict types at all — `type` is a free
string and `items` is passed through as a nested `array<string, mixed>`. What it
whitelists is the *keyword* list:

```php
foreach (['description','default','enum','format','items','pattern','minimum','maximum'] as $key)
```

So `type: object` with nested `items.properties` already survives today. What is
absent is `properties`, `oneOf`, `minItems`, `maxItems`, `additionalProperties`.
That is not architecture. **It is a twenty-line whitelist**, which is why the
export in the other direction is worth doing rather than working around.

### Why scalars are not merely inconvenient here

Scalar-only is not a neutral constraint that costs us a little flexibility. It is
a **security regression**, because the only way to express a command with arguments
and a working directory in a scalar schema is to quote a command line back into a
single string:

```
cmd: "cd packages/api && npm test"     ← what a scalar-only schema forces
cmd: ["npm","test"], workdir: "packages/api"   ← what we actually want
```

The first form reintroduces shell-string parsing, and that is the mechanism by
which `cmd` stops being "arbitrary but argument-safe" and becomes "whatever the
quoting rules permit". The design deliberately keeps `cmd` as an argv array so
arguments are never re-parsed. A schema that cannot carry an array would undo that
in the name of consistency with a tool registry that has nothing to do with it.

### The shape

`exec` takes a real DTO with Symfony validation attributes, and the JSON Schema is
**derived from it** rather than hand-written per tool:

```jsonc
POST /v1/sessions/refactor/exec
{
  "cmd":     ["npm","test"],
  "timeout": 900,
  "network": "none",
  "workdir": "packages/api",
  "env":     {"NODE_ENV":"test"},
  "stdin":   null
}
```

| Field | Type | Constraint |
|---|---|---|
| `cmd` | `list<string>` | **minItems 1**, each element non-empty. Arbitrary *as arguments*. |
| `timeout` | `int` | 1..14400, default 1800 |
| `network` | `enum` | `none` \| `session` — nothing else means anything |
| `workdir` | `string` | must resolve **inside** the workspace volume; a path escaping it is refused, not normalised |
| `env` | `map<string,string>` | keys `^[A-Za-z_][A-Za-z0-9_]*$`, capped count and value length |
| `stdin` | `string` | capped bytes |

Two notes on `env`, because it is newly expressible and therefore newly a
decision. It is the *container's* environment, which is not the sensitive thing —
the `CONTAINERS: 1` problem is the *host's* environment. But the broker must pass
**only the caller's declared env and inherit nothing of its own**: the PHP process
will be holding the Docker credential and the broker's own token, and an inherited
environment leaking into a container the agent controls is exactly how a bound in
one place becomes a hole in another. Explicit allow, never inheritance.

`mounts` stays out for now, deliberately, and not because it is inexpressible —
because it needs its own gate and its own decision. That is a different thing from
not being able to express it, and the difference matters.

### The export: better schema support in ContextShuttle

Do this in two tiers, and do tier A now — it is small, it is immediately useful,
and it makes the two repos meet at the same dialect instead of diverging.

**Tier A (in ContextShuttle, ~20 lines plus tests).** Widen the keyword
pass-through in `ToolDefinition::inputSchema()` to include `properties`,
`additionalProperties`, `items` (already there), `minItems`, `maxItems` and
`oneOf`. That alone lets a YAML tool declare a nested typed object, and its
existing tools are unaffected because the new keys are optional. It also has an
obvious first beneficiary: `cmd` as array-of-strings.

**Tier B (only when a tool earns it).** A DTO-derived schema path, for tools
complex enough that a PHP class with validation attributes reads better than YAML.
WorkWarp's `ExecRequest` is the proof-of-concept for that path; if it turns out
pleasant, ContextShuttle can adopt the pattern for `CreateTransactionTool` and
`MemoryDraftTool`, which are its two most parameter-heavy tools and the ones whose
current YAML is hardest to read.

**One dialect, and a test that keeps it honest.** The convergence point is the
output: valid JSON Schema (draft 2020-12), consumed identically by the MCP SDK and
the REST/OpenAPI layer. The anti-drift mechanism should be a test that asserts the
*published* schema actually validates a real call — ContextShuttle already has the
seed of this in `tests/Unit/Mcp/ToolInputSchemaTest.php`, which checks schema
*shape*; extending it to check that a valid payload validates and an invalid one
is refused is what stops the two implementations drifting apart silently.

**What not to do:** extract a shared schema package yet. Two consumers is the
classic threshold at which interface extraction is premature, and both codebases
are moving. Converge on the *output* first; extract once the shape has stopped
changing. A shared package designed today would encode today's guess.

---

## 6. Labelling is the *enforcement* mechanism, not decoration

This is where the "may not touch the neighbours" requirement is actually met.

- The broker owns *every* Docker call, across *all* sessions. So it can, for each
  request, apply `label=ww.created-by=work-warp` **and** `label=ww.session=<caller>`
  — and scope to the caller's own session, not merely to work-warp's.
- The broker is also the only thing that *stops*. **It holds the token, so it can
  validate every operation against label ownership.** That check is not available
  to the proxy and not available to the client. It is the whole reason the broker
  exists rather than a fatter `ww-run`.
- It never uses `container_id` or `volume_name` from the caller unvalidated: it
  resolves the session to its own resources and ignores anything named in the
  request. A submitted id is a *selector within the session*, never an authority.

The broker additionally never forwards:

```
/swarm /secrets /configs /plugins /nodes /services /tasks /session
/containers/{id}/exec/{start,resize}   ← contains the path, not just the verb
/images/*/push /build /commit /events /info
```

`/build` is interesting. The agent explicitly wants to build a project's real
image. That is an *intended capability*, not an escape. So the broker keeps a
build path — but in **scratch** form: session-scoped tag, `network` by enum,
result disposable, and nothing it produces can be pushed.

**Corrected: the "no new images" claim was wrong.** I wrote that `FROM` could
only reach the ~127 images already on the daemon, and that this was a smaller
grant than registry access. Measured:

```
POST /images/create?fromImage=ww-does-not-exist -> 404  FORWARDED
POST /images/create?fromImage=alpine&tag=latest -> 200  FORWARDED, and it pulled
```

`POST: 1` forwards `/images/create`, which is the registry **pull** endpoint. So
`--network` is the only control on what a `Dockerfile` can obtain, and the
"scratch build" is network-dependent rather than access-dependent. Two
consequences:

- The build story needs its own answer, not an appeal to the local cache. A
  `Dockerfile` with `FROM ghcr.io/anything` succeeds whenever `network` permits.
- Since the broker holds the only connection to the proxy, it is also the point
  at which a registry allowlist can be enforced — by refusing the pull and
  requiring the image to be present. That is a *later* decision, but it should be
  made deliberately rather than inherited from a wrong assumption.

There is also a judgement call worth flagging rather than silently banking: a
`FROM npmjs.com` style pull is *normal build behaviour* for the compose harness
in §9, not an attack. So the default should probably be `network: session` for
build (it is unusable otherwise) with the pull *logged*, not blocked.

---

## 6a. Language, and why the answer is PHP

**PHP 8.5 on FrankenPHP, matching ContextShuttle.** Not a compromise — the right
answer, for three reasons that a general-purpose service would get wrong.

FrankenPHP is a Go web server with the PHP interpreter embedded, so the process
is *already* long-lived and a Symfony application controls its own lifecycle.
That is exactly the shape this broker needs:

| Requirement | Idiom |
|---|---|
| Per-command timeout | `symfony/clock` + `symfony/lock` — both already in ContextShuttle's `composer.json` |
| The TTL janitor | A `messenger`-style loop or `frankenphp` worker, **no cron and no second service** |
| Concurrent, capped execs | `symfony/lock` as the semaphore replacing `WW_MAX_JOBS` |
| Structured logs + request IDs | `monolog`, already configured |
| Streamed command output | FrankenPHP supports streaming responses (the `RespondingStream` test helper in ContextShuttle exists precisely for this) |

And the real argument is internal: **the broker is a gate.** It has an allowlist of
operations and a scope policy. ContextShuttle already solves that problem five
times over — `FolderGate`, `CalendarWriteGate`, `PennyTrackWriteGate`,
`MemoryDraftGate` — with the same shape each time: a small service that decides
what is permitted, a typed refusal when it is not, and a unit test per rule. That
is the exact vocabulary this component needs, and reusing it means one reviewer
reads one idiom across both repos. A Go broker would be *technically* simpler and
organisationally worse.

**The concrete lift:** `src/Tool/Docker/ExecTool.php` + `config/tools/`
YAML + `config/services.yaml` wiring, in a repo laid out per
`private/ci/docs/STRUCTURE-FOR-NEW-PROJECTS.md` at the `api-gateway` profile.
Bootstrap and parametrisation are already solved; the new work is four gate
classes and the container spec.

---

## 7. Gap in `PLAN.md`: host configuration is inside `POST /containers/create`

`ww-run` refuses `--privileged`, `--pid=host`, host binds and `--net=host`. Those
refusals are *correct and insufficient*, because the same endpoint also carries
the host's entire runtime configuration. Anything the broker forwards as a
create payload can contain **all** of these:

```
Binds, Mounts, Devices, DeviceCgroupRules, CapAdd, SecurityOpt,
Privileged, PidMode, IpcMode, UTSMode, UsernsMode, CgroupParent,
NetworkMode, Sysctls, RestartPolicy, Runtime, HostConfig
```

This is an endpoint-granularity problem, not a payload-filtering one: there is no
"safe create" path to forward to. It is the same shape as `unshare` being gated on
`CAP_SYS_ADMIN` — one concrete fact that closes a whole family of designs. Here
it means:

- The broker does not forward the caller's `containers/create` **at all**. It
  builds the payload itself, field by field, from typed input.
- Field-by-field construction is the *only* form that works, because
  `Privileged: false` and `CapAdd: []` are the defaults — so a payload assembled
  from known keys cannot express what is absent from the source.

---

## 8. What this does and does not buy

**Buys:** an arbitrary command in a bounded container with a hard clock; a
workspace that persists while the container does not; Docker-level work
(build, up, test, down) without host access; and — the new part — **label-scoped
ownership enforced by something that holds the credential**, so "do not touch the
neighbours" is a check rather than a request.

**Does not buy:** defence against a container-escape CVE (that is the VM's job);
defence against a bug in the broker (that is the proxy's job, and review's);
immunity to resource exhaustion or to the host filling up. The broker is ~1 new
service and it is the only component that holds the token — which makes it the
component worth reviewing hardest, and the reason it must be small.

**Also not bought:** container-level *isolation from each other within a session*.
Two `exec` calls in one session can affect each other (they share `/workspace`,
by design). Isolation is per-*session*.

---

## 9. Where this changes `ww-run`

`ww-run` becomes the broker's client library — the ergonomics stay, the authority
moves. Concretely the broker replaces the daemon as the peer:

```diff
- DOCKER_HOST=tcp://lyra-dockerproxy:2375
+ WW_BROKER=http://ww-broker:8080   (+ WW_TOKEN)
```

and `--read-only`, no inherited env, and `--stop-timeout` get added to the
container spec. The refusals in `ww-run` stay: the broker makes them
unexpressible, and a client-side refusal that would fail anyway is a *better error
message*, not redundant work.

---

## 10. Build order

Revised from `PLAN.md` §12, which is unchanged in substance — the ordering just
now has a prerequisite it did not have.

**Step 0 — prove the topology before writing the broker.** Half a day, and it
falsifies the whole design if it fails. Four checks, and the second is the one
that matters.

### 0a. What is already true — measured 2026-10-07 from inside the session

Re-measured against the live stack, which turns out to be further along than this
document assumed. Three of the four checks are already half-answered:

- **The session *is* a container on the wire.** `hostname` is a container id, and
  `GET /containers/lyra-terminal/json` resolves. The earlier probe ran from the
  same place inside the terminal container, so its call to `/containers/{id}/top`
  was the terminal naming *itself*. That is a sharper demonstration of the
  problem than was written down: reads reached the host from this very container.
- **`lyra_backend` already contains exactly two containers** — `lyra-dockerproxy`
  and `lyra-terminal` — so §4's "subtractive" topology is not a proposal, it is
  what is running. The work is deleting one membership.
- **The proxy holds no credential.** It answers `200` to `/_ping`, `/version`,
  `/info` and `GET /containers/lyra-terminal/json` with **no auth header at all**,
  and identically with a bogus bearer. Its own config confirms it: `AUTH=0`, and
  every `ALLOW_*/HTTP_*` family is `0`.
- **Path filtering works and body filtering does not** — both confirmed live.
  `GET /secrets`, `POST /build`, `POST /exec/{id}/start` are `403`;
  `POST /networks/create` and `POST /containers/create` are `400`, i.e.
  *forwarded* and merely malformed. `POST=1` is doing exactly what §1.2 says.

Which means **check 3 does not test what it says it tests.** Calling the proxy's
`/_ping` from the session returns `200` today — but that is not proof the terminal
*presented* a credential, because the proxy accepts anonymous callers. A `200`
here is currently ambiguous, and will remain ambiguous until the proxy is
unreachable. So the order is: close the route, *then* a `200` becomes meaningful
(and impossible), and a `connection refused` is the pass condition. As written,
check 3 would report a false failure on a correctly configured stack.

### 0b. The one thing that must change, and it is six lines of compose

**Applied 2026-10-07, reconciled into the repo 2026-10-08.** The steps below are
kept as the record of the change; the file in this repo now states the same
values they produced.

1. `lyra-terminal`: **remove `lyra_backend`**, add `lyra_api`.
2. `lyra-terminal`: **remove the `DOCKER_HOST` line.** This is the step that was
   missing. §4 asks for the terminal to be off the proxy's network; it does not
   say to take away its address for the proxy. Leaving `DOCKER_HOST` set to a
   hostname that now resolves nowhere leaves the Docker client pointed at a dead
   name — which reads as "broken" rather than "removed", and invites someone to
   fix it.
3. Declare `lyra_api` (broker + terminal, and nothing else).
4. `lyra-dockerproxy`: unchanged — `lyra_backend` alone.
5. `ww-broker`, when it exists: `lyra_api` + `lyra_backend`.

Two smaller decisions bundled in, both since landed:

- **Pin the proxy image — done as `v0.5.0`.** The live container was
  `tecnativa/docker-socket-proxy:v0.5.0`; the compose file said `:latest` and the
  README flagged `← PIN ME`. The file now carries `v0.5.0`, the version every
  measurement was taken against. Resolving the tag to a digest is the stricter
  form of the same pin, left as a one-off for whoever is next at the host.
- **Settle the network name — done by changing the file to match reality.** The
  live network is `lyra_backend`; `deploy/compose.yaml` now declares `backend`
  and `api` (no `name:` override), which resolve to `lyra_backend` and
  `lyra_api` under project name `lyra`. Reality is the fact; the file agrees
  with it.

### 0c. The checks, rewritten so they can actually fail

1. From inside the session: `lyra-dockerproxy` does not resolve, or resolves and
   refuses. **Pass = no `200` and no route.** Not "the echo answers" — testing an
   echo proves less than testing the proxy itself, and the proxy is right there.
2. **Confirm the session cannot re-attach.** `docker network connect
   lyra_backend <self>` must fail. This is still the check that decides whether
   the removal is a boundary or a speed bump, and the `400` on
   `POST /networks/{id}/connect` is why.
3. Re-run check 1 *while holding the broker token*, so a network mistake cannot be
   hidden by an absent credential. The two halves must fail independently:
   network removal fails closed on access, the credential fails closed on
   authorisation, and neither is allowed to be the only reason the other passes.
4. Confirm the broker's port is *not* reachable from a `public` container
   (`task-loom` is the honest test, since it is the intended client and must
   therefore go through the broker's own auth rather than the network).

Only then does it make sense to write the broker, because checks 2 and 3 are what
decide whether "the api is unreachable" or "the api is unusable" is the real
constraint — and those lead to different code.

### 0d. Post-rollout measurement — 2026-10-07, after the compose change landed

The change was applied and the terminal rebooted onto the new topology. Measured
from inside it, with controls, because one unreachable hostname proves nothing:

| probe | result | establishes |
|---|---|---|
| `task-loom`, `context-shuttle`, `memory-draft`, `gitea`, `caddy` | resolve | positive control — on `public`, DNS works |
| `definitely-not-a-container` | no answer | negative control — the test *can* fail |
| `lyra-dockerproxy` | **no answer** | not on this network |
| `172.18.0.1:2375`, `172.26.0.1:2375` | `000` | no API on either bridge the session is on |

`DOCKER_HOST` is unset and `/var/run/docker.sock` is absent. `ww-run` is inert
and fails *loudly and correctly* — exit `1`, `docker create failed (… check
DOCKER_HOST)` — and `ww-run guard-test` still passes with no daemon present,
which is the right property for a guard that must not depend on the socket.

**Check 1: pass.** No `200`, and no route to anything that could answer with one.

**Check 2: pass by construction, and that is a weaker claim than it sounds.**
`docker network connect` now fails because there is no daemon to ask; on its own
that would prove nothing. The load-bearing fact is check 1: re-attaching requires
the API, and the API is unreachable from this network. The vector is closed by
the same measurement that closes check 1, not by an independent test of the
re-attach path.

**Check 3: still open, and now unblocked.** It needs the broker token, which does
not exist yet. It is the first check that can fail once the broker lands.

### 0e. Where the broker's own code goes, and the one gap in the plan

**Resolved 2026-10-08: the broker is a `src/` in this repo** (PLAN.md decision
log). The reasoning below is the record of why; the answer it leans toward is the
answer that landed.

§6a gives the language and the idiom but never says where the broker's source
lives, and this repo is not obviously the answer: `origin/main` contains eight
files and **no PHP at all** — no `composer.json`, no `src/`, no Dockerfile. So
the first commit of the broker is a bootstrap, and that is a shape decision rather
than a detail.

Two facts that settle part of it:

- **The terminal container had no PHP.** Measured at the time: `php: not found`,
  `composer: not found`. That was resolved on 2026-10-08 — PHP 8.5.11 and
  Composer were re-installed (`~/install-php85.sh`), so the broker can be
  developed here after all. The `intl` extension was the one gap in that script
  and was installed alongside; it is the extension §6.1's "just add `intl`"
  example names.
- **The broker is a service, not a script.** It holds the only Docker credential
  and its deliverables are a FrankenPHP image and a compose service on
  `lyra_api` + `lyra_backend`, not a binary someone runs by hand.

The decision that followed: **`src/` in this repo, not a second repo** — the
broker and `ww-run` are two ends of one protocol, and §9 already reframes
`ww-run` as the broker's client library, which is hard to keep coherent across
repos. It was made deliberately, against the `api-gateway` profile in
`private/ci/docs/STRUCTURE-FOR-NEW-PROJECTS.md`, rather than inherited from
whichever commit landed first.

### 6b. The concurrency cap: what the unit is, and why the mechanism is a pool

A step-2 amendment. §6a asked for "concurrent, capped execs" and named
`symfony/lock` as the semaphore replacing `WW_MAX_JOBS`; it did not say what the
cap is *of*. Two things had to be decided, and one of them turned out to be
about the design rather than about the number.

**1. The unit is the workspace, not the session.**

`POST /v1/sessions` creates a session and its workspace together, so today a
per-session cap and a per-workspace cap are indistinguishable. They come apart
the moment sessions can share a workspace — which `PLAN.md` §6.3 already looks
forward to, and which is the natural shape when a session's *work* changes while
its *directory* should not. So the rule has to be stated in the form that stays
true:

> A workspace is the unit that has a budget. Its size is a property of the
> workspace.

What that buys, in the two cases that differ:

| Case | Budgets | Why it is right |
|---|---|---|
| Two sessions share one workspace | **one**, shared | They share the volume, so they contend for the same disk and the same daemon; two budgets would let a shared workspace run twice as much work as a private one. |
| One session, two workspaces | **two**, independent | The workspaces do not contend, and the second one should not wait on the first. |

Nothing in the implementation depends on resolving this now: the pool is cached
per capacity, so when capacity becomes a workspace property the resolution is
what changes — the mechanism already works. What *would* have been expensive is
writing "per session" into the API and discovering the difference later.

**2. The mechanism is a pool of file locks, not a counter.**

`FlockStore` has no capacity — it is `flock()` on a hashed filename, one lock per
key, so it is a mutex and not a semaphore. Capacity is therefore **N distinct
slots**, each its own lock, and "how many are running" is "how many slots are
taken". A counter in a variable would be smaller and would be lying: after a
crash it says four commands are running and nothing can say whether that is
true. A slot is held by a file description in the kernel, so a dead holder is
a free slot with no reconciliation step.

Three details that are not obvious and were each worth measuring:

- **Every reservation must be a fresh `Lock` object.** A `Lock` is re-entrant
  with respect to itself: a second `acquire()` on the same object succeeds,
  because the store reports it as already held by this token. Handing out one
  cached `Lock` per slot let the first implementation exceed its own capacity —
  it issued three reservations from a pool of two, while cross-process behaviour
  was correct all along. Fresh objects make the same-process case go through the
  same conflict path as a foreign process, so there is one mechanism rather than
  two that have to agree.
- **`Lock::isAcquired()` cannot answer "is this slot free".** It reports only on
  the object you already hold. Asking whether a slot is available means
  *acquiring it and letting go*, which is also why `available()` is for
  reporting and never for deciding: the answer can change before the caller acts.
- **`modify('+1.000 seconds')` is silently ignored.** PHP's relative date format
  accepts fractional units and quietly does nothing with them, so a deadline
  built that way never arrives and a caller that asked to wait gets an immediate
  refusal instead. The wait is arithmetic on floats for that reason.

**Not built, deliberately: a queue.** The default is to refuse immediately, with
a message naming the capacity and saying the broker does not queue. A broker that
silently queues has made the queue the caller's problem without telling them, and
the caller cannot then distinguish "waiting" from "hung" — which is the exact
confusion this design exists to remove. `reserve(wait: …)` exists for a caller
that has decided to wait, and it is opt-in.

**Scope limitation, stated so it is not assumed away:** flock is local to one
machine, so two brokers running against one daemon would not share a budget.
The design has exactly one broker, so this is not a gap — but it is the reason
the cap is a property of the workspace and not of the daemon, and it would need
revisiting before anyone ran a second broker.

**Step 1 — the ledger + session record.** Unchanged from `PLAN.md` §6.2. Still
the cheapest durable thing and still no Docker needed. `POST
/v1/sessions/{name}/exec` wants somewhere to write its result.

**Step 2 — `exec`, the 95% win.** Container per command, workspace volume, caps,
timeout, output caps. At this point the product works and is usefully different
from today.

**Step 3 — the janitor and `reap`.** TTL labels are already written; this is the
thing that reads them. This is where "cannot sit there forever" becomes true even
when the broker is dead.

**Step 4 — `build` (scratch), then the compose harness.** A real
`docker compose up` test against a real stack. `PLAN.md` §9's task-loom e2e
becomes a normal operation.

**Step 5 — the MCP surface.** Last, deliberately. The MCP server is a thin
wrapper over the broker's API and the API is the thing worth getting right;
writing the wrapper first would freeze the wrong interface. ContextShuttle is the
template for the PHP/OpenAPI plumbing, and it is a good one — but its shape should
follow this API, not lead it.
