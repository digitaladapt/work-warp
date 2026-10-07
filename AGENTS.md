# AGENTS.md

## Permission boundaries — read this before touching anything

Some operations are not merely discouraged; you lack permission, and any attempt
will always fail. Don't try them, don't work around them:

- Changing **anything** under `.gitea/workflows/`. (A server-side pre-receive
  hook rejects pushes that modify workflow files from untrusted refs.)
- Merging into or pushing to `main`.
- Reviewing, approving, or merging pull requests.
- Creating or pushing tags beginning with `v` — those are reserved for
  versioning.

## Required workflow

- Branch from `main` for every change. Branch names use one of these prefixes:
  `feat/`, `fix/`, `cleanup/`, or `chore/`.
- Run whatever quality gates exist before pushing, and fix or revert anything
  they flag.
- Push the branch and open a pull request against `main`.
- Notify the user with the PR link. The user merges.

## Project overview

WorkWarp gives an agent a terminal, a workspace and a filesystem for long-running
tasks. Read [`docs/design/PLAN.md`](docs/design/PLAN.md) before changing anything;
it records the decisions, the evidence behind them, and what is still open.

Three things worth knowing before you touch the design:

- **The workspace is not an identity.** It is a directory on a persistent volume.
  The *command* is the identity — its own container, its own UID, its own
  namespaces, no ambient credentials.
- **The VM is the security wall; the broker is guardrails.** Filtering the Docker
  API by endpoint does *not* stop a hostile request body. Don't describe the
  broker as a boundary.
- **State lives in the volume, never in a process.** A session that lasts months
  cannot be a live process; long-lived processes leak.

## Style

Prose in this repo is plain and specific. Prefer a verified observation over a
plausible claim, and say plainly when something is an assumption rather than a
finding. Where a claim was tested, say how.
