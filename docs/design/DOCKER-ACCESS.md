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
`deploy/compose.yaml`, measured:

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

The point is not a new network. It is that **the session must not share a
network with the proxy.** One hop, one narrow surface:

```
   public                lyra-api              lyra-control
   ──────                ────────              ────────────
   lyra-webui            ww-broker ──────────── lyra-dockerproxy ──▶ dockerd
   lyra-terminal ────────┘    ▲                 (2375, no other member)
   (no lyra-control)          │
                              └── the only thing the session can reach
```

- `lyra-terminal`: `public` + **`lyra-api`** (and *not* `lyra-control`).
- `ww-broker`: `lyra-api` + `lyra-control`. Holds the only route to the proxy.
- `lyra-dockerproxy`: `lyra-control` only.

A new `lyra-api` (broker + terminal, nothing else) rather than putting the broker
on `public`: every service on `public` — jellyfin, immich, vaultwarden,
nextcloud, gitea and the rest — would otherwise be able to reach the broker. The
broker is a new attack surface and it should be visible to exactly one client.

**Removing `lyra-control` from `lyra-terminal` is the single most important line
in this document.** Without it, the broker is advisory, because the API is still
one `curl` away.

> Untested. This is a topology to try, not a measurement. It is also *simpler*
> than what is in `compose.yaml` today, not more complex.

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

Every field is typed and bounded:

| Field | Permitted | Notes |
|---|---|---|
| `name` | `[a-z0-9][a-z0-9-]{0,30}` | becomes `ww-ws-<name>`; validated, never interpolated |
| `cmd` | array of strings | **arbitrary** — that is the point |
| `timeout` | 1..14400 seconds, default 1800 | the broker kills at expiry |
| `network` | `none` \| `session` | an enum. Nothing else means anything. |
| `stdin` | string, capped | optional |
| `tag` | `[a-z0-9._-]{1,40}` | becomes `ww-<session>-<tag>` |

There is deliberately no `env`, `mount`, `privileged`, `cap_add`, `user`,
`entrypoint`, `hostname`, `label` or `name`-beyond-the-tag. `network: session`
is the only thing that grants any reach, and it points at a network the broker
created and named.

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
result disposable, and nothing it produces can be pushed. Dockerfile `FROM`
therefore reaches whatever the local daemon already has, which is ~127 images
including `alpine`, `caddy`, `postgres`, several Node and PHP bases. That is
enough for the milestone-4 compose test harness and it is a strictly smaller
grant than general registry access, so a registry allowlist is a deliberate
deferral rather than an oversight.

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

**Step 0 — prove the topology before writing the broker.** Remove
`lyra-control` from `lyra-terminal`, add `lyra-api`, put a stock HTTP echo on it,
and confirm from inside the session: the broker's port answers, and
`lyra-dockerproxy:2375` does not. Half a day, and it falsifies the whole design
if it fails.

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
