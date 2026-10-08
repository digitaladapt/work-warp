# Security Policy

## Supported versions

Only the latest release line receives security fixes.

## Reporting a vulnerability

Report privately to the maintainer (see the security contact in the Gitea repo
settings). Please include:

- Description of the issue and its impact
- Steps or proof-of-concept to reproduce
- Affected endpoints or flags

You will receive an acknowledgement within 72 hours. Please avoid public
disclosure until a fix is released.

## Scope notes — read these before deploying

**WorkWarp hands an agent the ability to run arbitrary commands and to create
containers. Deploy it only where that is the intent.**

- **The broker is guardrails, not a boundary.** It makes escalation
  *unexpressible* — its API has no field for `--privileged`, a device, a bind
  mount, a capability, a host namespace or an arbitrary container name — and it
  enforces label-scoped ownership on everything it touches. It is not a
  substitute for the daemon being somewhere disposable. A container-escape CVE,
  or a bug in the broker itself, is not in its threat model. Run the daemon in a
  VM if that threat matters to you.
- **Whoever holds `WW_TOKEN` can run arbitrary commands** inside a bounded
  container, and `cmd` is arbitrary by design. The token is not a
  capability-narrowing mechanism; the *container* is what is constrained.
  Generate it with `openssl rand -hex 32`, inject it at runtime, and never commit
  it. `.env` is gitignored; `.env.example` documents the safe defaults.
- **Do not mount `/var/run/docker.sock` anywhere except the socket proxy.** A
  process with socket access has host root by definition, and every guarantee
  above is void. The proxy exists so that exactly one small, shell-less
  container holds it.
- **The socket proxy is defence-in-depth, not the boundary either.** Measured:
  it filters by endpoint and does not inspect request bodies, so `POST
  /containers/create` forwards a `--privileged` payload. That is precisely why
  the broker builds create payloads field by field from typed input rather than
  forwarding anything a caller sends.
- **The session must not share a network with the proxy.** If it can reach the
  proxy, it can re-attach itself to the proxy's network in one request and the
  broker becomes advisory. This is verified by measurement, not by convention —
  see `bin/probe-guardrails.sh` and `docs/design/DOCKER-ACCESS.md` §10.
- **Secrets belong in environment variables, never in the image or the repo.**
  The design goal is that an agent can run commands that *need* credentials
  without being able to see or know them; inherited environments are the classic
  way that goal is lost, which is why every command container gets an explicitly
  declared environment and inherits nothing.

## Known accepted exposure

`tecnativa/docker-socket-proxy` exposes `GET /containers/{id}/json`, which
returns `Config.Env` with values for every container on the daemon —
`CONTAINERS=1` cannot be split into list and inspect. On the current host that is
825 environment variables, 134 of them sensitive-looking. This is a milestone-2
item, accepted knowingly and recorded in `deploy/README.md` and
`docs/design/DOCKER-ACCESS.md` rather than left as an oversight.
