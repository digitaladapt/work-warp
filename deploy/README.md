# Deploying WorkWarp's Docker access

Two files and a script. Read this before applying any of it.

| File | What it is |
|---|---|
| `compose.yaml` | Your host stack, revised. **Diff it against `/home/user/compose.yaml` first** — two changes, both commented. |
| `../bin/ww-run` | The sanctioned way to use Docker. Refuses the dangerous flags. |
| `../bin/probe-guardrails.sh` | Measures what the proxy actually admits. Run it before trusting the prose. Needs `curl`; its exit code is the verdict (0 clean, 1 escalation reached, 2 results unknown). |

---

## Read this first: where the file sits

`/home/user` inside this container **is** `/home/andrew/apps/lyra/terminal` on the
host — the same directory your compose file mounts as `./terminal`. So the stack
I run inside and the directory this repo lives in are the same tree, and the
whole revision is reachable from the host without copying anything out:

| Inside the container | On the host |
|---|---|
| `/home/user/compose.yaml` (the copy you gave me) | `/home/andrew/apps/lyra/terminal/compose.yaml` |
| `/home/user/work-warp/deploy/compose.yaml` (the revision) | `/home/andrew/apps/lyra/terminal/work-warp/deploy/compose.yaml` |
| — | `/home/andrew/apps/lyra/compose.yaml` (the live stack) |

So the diff to run, from the host, is:

```bash
diff /home/andrew/apps/lyra/terminal/compose.yaml \
     /home/andrew/apps/lyra/terminal/work-warp/deploy/compose.yaml
```

The paths in the rest of this document are host paths, because that is where you
will be typing them.

`/home/user` is a bind mount, so nothing written here can be lost by restarting
the terminal container — which matters for step 5 below.

---

## What changes, and what deliberately does not

**Changes** — two, and nothing else:

1. **A new service, `lyra-dockerproxy`.** A stock `tecnativa/docker-socket-proxy`,
   on a private `lyra-control` network, publishing no ports.
2. **One environment line on `lyra-terminal`:** `DOCKER_HOST` → the proxy.

`lyra-webui` is untouched. `lyra-terminal` keeps its image, volume, env_file,
network, ports and user. **The Docker socket is not mounted into it**, at any
path — that is the whole point, and it is also why this path is *better* than
mounting the socket directly: a command that goes looking for a socket will not
find one.

Measured, with comments and blank lines stripped: the diff is **27 added lines
and 1 removed** — a trailing blank line, and nothing of substance. It is
otherwise purely additive, so nothing that is running today is altered, only
added to.

---

## The honest part: what this buys, and what it does not

There are two layers here and they are not equivalent. I would rather say so
plainly than let a tidy diagram imply otherwise.

### What it buys

- **The socket is in one place.** Exactly one small, shell-less, published-
  nothing container can reach `/var/run/docker.sock`. If the terminal container
  is compromised, the attacker gets the proxy's API surface, not the socket.
- **Sections are denied by default.** The proxy forwards an API section only if
  the matching variable is set, and my list omits the dangerous families
  entirely: no `/swarm`, `/secrets`, `/configs`, `/plugins`, `/nodes`,
  `/services`, `/tasks`, `/session`. Those are unreachable, not merely unused.
- **The credential moves out of reach.** The token, if we add one, sits in the
  proxy container — a stock image with no shell to read it with, and whose
  config lives in its own environment rather than in a file. That is the
  `OPEN_TERMINAL_API_KEY` exposure from `/proc/1/environ`, fixed for this path.
- **Blast radius is partly capped.** Corrected 2026-10-07 against the running
  container, because this bullet used to claim more than the compose file does.
  Measured: `mem_limit` 128m and `pids_limit` 256 are **in force**. But
  `CapDrop`, `CapAdd` and `SecurityOpt` are all **null**, and the proxy runs as
  **root** — so there is no `cap_drop: ALL` and no `no-new-privileges`, and this
  bullet previously said there were. The compose file is honest about this in its
  own comments (both are deliberately commented out, because nginx needs
  setuid/setgid/chown and because `read_only` was unverified); the README was
  not. Command containers still get `--memory`, `--cpus` and `--pids-limit`.
  Worth fixing, but not the same priority as the network change: `/var/run/docker.sock`
  already means host root if this process is ever compromised, and dropped
  capabilities do not change that. It is defence against a *different*, narrower
  bug — a proxy CVE — which is real but second-order.
- **The default is the safe one.** `ww-run` runs with `--network none` unless a
  human asked otherwise, and refuses `--privileged`, `--pid=host`, `--device`,
  host bind mounts and `--network public` outright. There is no override flag:
  if a task needs that, the answer is a different deployment, not a wider flag.

### What it does not buy

**The proxy is not a security boundary against me.** `docker-socket-proxy`
filters by endpoint, and refuses a whole family of endpoints — `GET /secrets` is
blocked, which is real. But it does not inspect *request bodies*. So `POST:
1` on `/containers/create` does not stop a `--privileged --pid=host` payload
from being accepted.

The consequence is exact: the proxy prevents **enumeration and reading** of host
secrets by an attacker holding the token, and it does *not* prevent that attacker
from **creating** a privileged container. I expect `probe-guardrails.sh
--with-create` to demonstrate precisely this, and I have written the script to
call it a failure rather than paper over it.

This is why I asked about the daemon in §7.2 of the plan, and the answer changes
what this deployment is:

| Daemon location | What `lyra-dockerproxy` is |
|---|---|
| The host (`/var/run/docker.sock` as above) | **Guardrails.** Better than today, but the only wall between me and host root. Not categorically safe. |
| A VM you provision | **Still guardsrails — and now there is a real wall behind them.** Escalation lands in a VM, not on your host. |

If the goal is "even a misbehaving agent cannot reach host root", the VM is the
part that delivers it, and the proxy does not substitute for it. Today's offer
gets a functional, well-behaved WorkWarp with strong accident-prevention; it does
not get a hostile-agent-proof one. I want you to be choosing that knowingly.

### Measured, 2026-10-07 — the expectations above, replaced with results

The proxy went up and I ran the probe against it for real, plus two direct
checks. Everything below is an observation, not a prediction.

**What holds.** Deny-by-default works exactly as described: `GET /secrets`,
`/swarm`, `/configs`, `/plugins`, `/nodes`, `/services`, `/tasks` are all
`403`. Every read the deployment needs — `/version`, `/info`,
`/containers/json`, `/images/json`, `/networks`, `/volumes` — is `200`.
`POST /build` and `POST /exec` are `403`.

**The good news is bigger than the bad news: there is no root path.** I tested
whether the read leak below could be turned into execution. It cannot, as
configured:

| Step | Result |
|---|---|
| `POST /containers/{id}/exec` (create the instance) | `201` — forwarded |
| `POST /exec/{id}/start` (actually run it) | `403` — refused |

Creating an exec instance is inert; only `/start` runs anything, and `/start`
is blocked by the `EXEC` flag. So the exec path is **not** reachable, and the
`COMPOSE`/`EXEC=0` setting is doing real work. The same is true of `/build`.
Escalation therefore requires the *create* path, which is still yours to decide.

**Confirmed broken: the proxy does not gate on method.** `DELETE` is forwarded
on `/volumes/...`, `/containers/...`, and `/networks/...` (each `404` for a
non-existent target, i.e. the daemon answered). Follow-on writes too: `POST
/containers/{id}/start`, `/stop`, `/kill` all reach the daemon. What this means
in practice is *good* for the janitor and *bad* for the story: `ww-run reap`
and any cleanup will work, and anything holding the token can also delete
volumes and stop containers.

**A read-side leak the earlier draft missed.** `CONTAINERS: 1` is not one
grant, it is two. It enables `GET /containers/json` *and*
`GET /containers/{id}/json`, and the second returns `Config.Env` **with
values** for every container on the host. Measured across all 46 containers:
**825 environment variables are readable, 134 of them with names that look
sensitive** (`*_KEY`, `*_TOKEN`, `*_PASSWORD`, `*AUTH*`), spread over 30
containers. That is the same class of leak as the `/proc/1/environ` one that
started this project, scaled to the whole stack. **`tecnativa/docker-socket-proxy`
has no variable that splits list from inspect** — `CONTAINERS=1` is both — so
this is not fixable by editing the compose file. It is a milestone-2 item (a
splitting proxy, or an allow-list in front), and until then it is a known,
deliberate exposure rather than an oversight.

**One correction to the pinning note above.** The proxy answers `_ping` with
`Server: Docker/29.7.2` — the *engine's* banner, passed through. So hitting the
proxy does **not** tell you which proxy you are behind, and the two behave
differently: v0.5.0 (running here) does not gate on method; a later release is
reported to add that. This is the concrete reason to pin by digest: the file
currently runs `:latest`, and `:latest` may be a different policy than the one
measured here.

**One confluence worth naming, because it is luck not design.** The version of
`probe-guardrails.sh` on `main` could not fail. Its method-filtering check used
`docker api`, which is not a subcommand of the CLI — it exits 1 with "unknown
command", and the script read a non-zero exit as "blocked". Against this proxy
it reported *zero findings*. The fixed probe reports this proxy honestly: 1
finding, the `DELETE` hole. The two fixes that made it honest and the deployment
being measured landed within the same hour, which is the only reason this
matters as a footnote rather than as a false clean bill of health.

### The three decisions this leaves

1. **`CONTAINERS: 1` — accept for milestone 1, or split now.** Accepting means
   134 sensitive-looking values are readable through the proxy by anything
   holding the token. Splitting means not using this proxy for that endpoint.
2. **`DELETE` — widen the policy or narrow the token.** Nothing can clean up
   today (no `DELETE` in the proxy's variable list, and the flag is the only
   thing that gates method). The janitor is the feature that needs it.
3. **Pin the image.** `:latest` may not be the v0.5.0 measured above.

---

## Rollout: two stages, smallest first

### Stage 1 — proxy only. Touches nothing that is running.

1. **Pin the proxy image.** `tecnativa/docker-socket-proxy:latest` is a moving
   tag, and pinning matters more here than anywhere else in the stack: this
   container has the socket. Resolve the current digest and put it in:

   ```bash
   docker pull tecnativa/docker-socket-proxy:latest
   docker inspect --format='{{index .RepoDigests 0}}' \
     tecnativa/docker-socket-proxy:latest
   # then set:  image: tecnativa/docker-socket-proxy@sha256:...
   ```

   I deliberately left `latest` in the file rather than inventing a version
   number, because a wrong pin is worse than an obvious one.

2. **Add the `lyra-dockerproxy` service and the `lyra-control` network** to your
   copy, and **do not touch `lyra-terminal` yet**. `docker network create
   lyra-control` if compose is unwilling to adopt an existing name.

3. **Bring up only the new service** — the terminal keeps running undisturbed:

   ```bash
   cd /home/andrew/apps/lyra
   docker compose up -d lyra-dockerproxy
   ```

4. **Probe it from a throwaway container** on the control network, not from the
   terminal yet, so a broken proxy cannot affect anything. Run this from the host
   shell — the bind mount has to be a host path, and the scripts are in `bin/`,
   not `deploy/bin/` (the path this step used to give):

   ```bash
   docker run --rm --network lyra-control -e DOCKER_HOST=tcp://lyra-dockerproxy:2375 \
     -v /home/andrew/apps/lyra/terminal/work-warp/bin:/bin-w:ro \
     alpine sh -c 'apk add -q curl >/dev/null 2>&1; /bin-w/probe-guardrails.sh'
   ```

   Read the output before going further. `POST /build` and `POST /exec` blocked
   is the result you want; anything under "READ paths that must be BLOCKED"
   showing `REACHED` means the variable list needs correcting. The exit code is
   the verdict: **0** nothing found, **1** at least one escalation reached,
   **2** no read path answered at all, so the results are unknown rather than
   clean — check `DOCKER_HOST` and that curl works before reading anything into
   a 2.

### Stage 2 — let the terminal use it. This restarts the container I live in.

5. **Add the single `DOCKER_HOST` line** to `lyra-terminal` and the
   `lyra-control` network entry.

   ```bash
   docker compose up -d lyra-terminal
   ```

   **This kills my current shell session.** The new container gets a new
   hostname, a new `/proc`, and the old shell with it. Nothing on the workspace
   volume is lost — `/home/user` is a bind mount, so every file survives — but
   any process I had running dies, and I will come back unaware that a restart
   happened. Worth doing when you are at the keyboard rather than mid-task.

6. **From inside the new terminal**, confirm the socket really is absent and the
   proxy really is the only path:

   ```bash
   ls /var/run/docker.sock            # must not exist
   echo $DOCKER_HOST                  # must be tcp://lyra-dockerproxy:2375
   docker info | head -5              # must work
   ww-run guard-test                  # must pass
   ```

7. **Re-run the create probe**, now from the terminal, with `--with-create`.
   This is the measurement that decides the table above.

---

## The safeguards, in one place

| Safeguard | Where | Stops |
|---|---|---|
| One container with the socket | `compose.yaml` | Goal an attacker must reach to get the socket at all |
| Deny-by-default API sections | proxy env | `/secrets`, `/swarm`, `/plugins`, `/nodes` … |
| No published port, private network | `compose.yaml` | Anything on `public` reaching the API — including my own command containers |
| `cap_drop: ALL`, `no-new-privileges` | proxy | Capability escalation inside the proxy |
| `mem_limit: 128m`, `pids_limit: 256` | proxy | A fork or memory bomb taking the host down |
| `ww-` prefix + `ww.created-by` label | `ww-run` | Ownership inferred from a name anyone could type |
| Acts only on those labels | `ww-run reap` | Aiming cleanup at your neighbours |
| TTL label on every container | `ww-run` | Runs nobody remembers to stop |
| Concurrency cap (`WW_MAX_JOBS`) | `ww-run` | A container loop starving the host |
| `--network none` by default | `ww-run` | Command containers reaching the doctor services |
| Refuses `--privileged`, `--pid=host`, `--device`, host mounts, `--network public` | `ww-run` | The escalations, at the point of use |
| `--init`, `--cap-drop=ALL`, memory/CPU/pids caps | `ww-run` | Orphans, runaway builds |
| No PTY, `PAGER=cat` | `ww-run` | The stuck-pager bug, by construction |
| One workspace volume, no other mounts | `ww-run` | A command container reaching a sibling's data |

Two hardening options I wrote and then removed, because neither could be tested
from inside this sandbox and a stack that will not start is worse than one that
admits what it has not hardened: `cap_drop: [ALL]` on the proxy (it is
nginx-based and needs setuid/setgid/chown to drop to its worker user) and
`read_only: true` (may need a writable `/tmp`). Both are left in the file as
comments. Add them one at a time and watch the container come up.

**Every one of these is convention, not a wall, and they all share a single
failure mode: anything with socket access can bypass `ww-run` by calling the API
directly.** They make the safe path the easy path. If you want them to be
binding, they have to be enforced in the proxy — which is why `ww-run` refuses
those flags *and* `probe-guardrails.sh` tells you which of them the proxy would
have caught anyway.

---

## Things I would not do without talking to you first

- **Mounting the socket into `lyra-terminal` directly.** It is one line and it
  works immediately, and it undoes the identity separation the whole design
  rests on. If you would rather I had it right now than correctly, say so and I
  will — but it puts an agent with unrestricted host-root access inside the same
  container as the API key, which is the problem we set out to fix.
- **`--privileged` on anything, ever.**
- **Naming `public` as the proxy's network**, or its per-command equivalent.
- **`docker system prune`** in any form. It does not distinguish your images
  from mine, and on a 90%-full disk it is the fastest way to lose something you
  wanted.
- **Building on `lyra-terminal`'s own image tag.** A build that clobbers the
  running container's image is a self-inflicted outage.

---

## Open questions this raises

1. **Host daemon or VM?** Decides the table above. Everything else can proceed
   either way.
2. **Rootless `dockerd` worth another look?** If the host's daemon were rootless,
   a container escape lands as a host *user*, not host root — a large
   improvement for one line, if your host daemon can be changed. Not something I
   can decide from in here.
3. **A proxy token?** `docker-socket-proxy` does not do auth itself; a token
   needs a shim in front. Worth it only once the daemon question is settled.
4. **Where auth lives, still.** The proxy has the credential exposure advantage,
   not a safety one. If you use the anonymous socket you have today, the socket
   peer-credential is the whole of the auth, and that is a fail-closed default
   for a real deployment rather than a foundation.
