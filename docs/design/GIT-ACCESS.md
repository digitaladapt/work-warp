# WorkWarp — Git access, milestone 1

Companion to `PLAN.md` and `DOCKER-ACCESS.md`, narrowed to one question: **how
does the agent push a feature branch without ever holding the credential that
pushes it?**

Written 2026-10-15, in the `workstation` session. Anything asserted here as
*measured* was measured that day from inside a command container; anything not so
labelled is an assumption, and §7 collects the assumptions that matter.

---

## 1. The answer, up front

**One MCP tool, `push_branch`, and a credential the broker holds and the session
never sees.** The tool takes a branch name and nothing else. The broker resolves
the remote itself, supplies the URL itself, pushes a single ref to a single
destination, and skips hooks — and the credential is injected into *that* push
only, never into a command container's environment.

> **This is not a new mechanism.** It is PLAN.md §3.4 — *"credentials never reach
the shell … injected into that container, at the last moment, in memory, scoped
to that command"* — and it is DOCKER-ACCESS.md §2 applied to git: a small
capability-shaped service in front of the powerful thing, designed so the
dangerous operations are **not expressible** rather than merely refused.

### Why not the ssh key

The ssh key is the mechanism this harness already uses, and `PLAN.md` §2.2
files it as a **defect of the thing WorkWarp exists to replace**:

> `~/.ssh/id_ed25519` — the private key — is present and readable.

Beside it, §2.2 states the governing principle — the agent must never be able to
see or know any secret or key while still being able to run commands that need
one — and concludes that no amount of environment-scrubbing satisfies it while
the shell shares a UID with the harness. Handing this project's own agent an ssh
key would re-introduce, in WorkWarp, the exact condition WorkWarp was built to
remove. That is the whole argument, and it does not depend on what §3 measures
below.

---

## 2. What is already true — measured 2026-10-15

From a command container in the `workstation` session, against
`/workspace/work-warp`:

| Question | Result |
|---|---|
| What transport does `origin` use? | **HTTPS**, not SSH: `https://code.devgnome.com/public/work-warp.git` |
| Can it be read anonymously? | **Yes.** `git ls-remote origin` succeeds with **no credential at all**, listing `refs/heads/main` and `refs/heads/chore/changelog-workspace-write-cap` |
| Is the remote reachable from a command? | **Only when asked.** Under the default `network: none`, `getent hosts code.devgnome.com` fails; under `network: session` it resolves (192.168.4.98) and the endpoint answers `200` for `info/refs?service=git-upload-pack` |
| Is the checkout's git state writable by the command? | **Yes.** Both `.git/config` and `.git/hooks` are writable by the uid the command runs as |

Two consequences follow immediately.

**Fetch needs no secret under any design.** The repository is world-readable, so
read access is already whatever `run_command` with `network: session` can do.
The entire question is *push*.

**The credential is needed for one operation, which means it can be scoped to
one operation.** There is no "git access" capability to grant. There is a push.

---

## 3. Why `--no-verify` is necessary, and why it is not sufficient

Two mechanisms in the *working tree's own* `.git` directory act on a push. Both
were measured with a local bare repository used as the remote, because the
finding is about git's behaviour and not about the forge.

### 3.1 `pre-push` runs under whatever credential the push holds — and reads it

A hook installed at `.git/hooks/pre-push` runs on `git push`, and an
environment-injected credential is **visible to it**:

```
PUSH: HOOK-RAN sees_cred=hunter2
PUSH:  * [new branch]      HEAD -> a
```

Measured, and the shape of the risk is bounded by one detail worth recording:
when the hook committed during its own run, the pushed ref was still the commit
that existed when the push began. The hook reads the credential; it does not get
to rewrite what is being pushed.

`git push --no-verify` skips it. The same push with `--no-verify` produced no
hook output at all.

So "inject the credential into the environment and let `git push` run" is not a
hiding place: it hides the secret from the *user* and hands it to whatever code
happens to sit in the repository. Skipping hooks is required.

### 3.2 `--no-verify` does not stop the repository choosing the destination

The repo-local config can rewrite where a remote points:

```
$ git config url."/workspace/hk3/evil.git".insteadOf "/workspace/hk3/srv.git"
$ git push --no-verify origin HEAD:refs/heads/c
To /workspace/hk3/evil.git
 * [new branch]      HEAD -> c
```

Measured: the push named `origin` went to the **other** repository, and the
credential went with it. `--no-verify` has no bearing on this — it is config, not
a hook. Both `.git/config` and `.git/hooks` are writable by the command (2), so
neither can be treated as trusted input.

This is the finding that decides the design, and it is not about hooks at all:
**if the client can name the destination, the client can choose the destination.**
No amount of hook-skipping fixes that, because there is no hook involved.

---

## 4. The shape

### 4.1 The tool

One verb. `push_branch` takes a branch name and returns a report.

```
push_branch(branch)  → {branch, ref, remote, pushed, url, output}
```

| Field | Permitted | Notes |
|---|---|---|
| `branch` | `^(feat\|fix\|cleanup\|chore)/[a-z0-9][a-z0-9._-]*$` | the vocabulary `AGENTS.md` already defines; validated, never interpolated |

There is deliberately no `remote`, no `url`, no `refspec`, no `force`, no `tag`,
no `args` and no `hooks`. This is DOCKER-ACCESS.md §2's rule read across: not a
denylist but a vocabulary that lacks the words. `--force` is unexpressible, tags
starting `v` are unexpressible (there is no tag parameter at all), and
`refs/heads/main` is unexpressible because the prefix grammar cannot produce it.

### 4.2 The broker's side

The broker does four things the client cannot do, and each one closes a finding
from §3:

1. **It supplies the destination URL itself.** The client's remotes and the
   client's `url.*.insteadOf` are never consulted. That is the fix for §3.2, and
   it is a fix by construction rather than by checking.
2. **It pushes from a tree it controls, not from the workspace's `.git`.** The
   broker makes a scratch clone of the revision being pushed, with
   `GIT_CONFIG_GLOBAL` and `GIT_CONFIG_SYSTEM` pointed at `/dev/null` and a repo
   config the broker wrote itself. Nothing in the workspace's `.git/config` is
   read, which is what makes point 1 true even for keys git resolves late.
3. **It pushes exactly one ref, by object id:** `<sha>:refs/heads/<branch>`, where
   `<sha>` is resolved by the broker at the moment of the call. A concurrent
   process in the workspace cannot substitute a different commit between the
   request and the push.
4. **It passes `--no-verify` anyway.** Belt and braces: in the scratch-clone
   shape the workspace's hooks are not reachable in the first place, and the flag
   means a hook in the *broker's* clone cannot run either.

The returned `url` is the forge's compare/PR page for the pushed branch, so the
"notify the user with the PR link" step in `AGENTS.md` has a link to give.

### 4.3 The credential

One push-scoped credential for one repository, held by the broker like the
Docker credential already is (DOCKER-ACCESS.md §5). Two workable forms:

- **A Gitea deploy key with write access to this repository only.** Per-repo,
  revocable without touching anyone's account, and it cannot reach any other
  repository on the forge even if it leaks.
- **A fine-grained token scoped to this repository and to push.**

The deploy key is the better default for the same reason the broker's Docker
credential is scoped: the blast radius of a leak should be one repository, not
one account.

Note that the broker's transport is **independent of the checkout's**. The
workstation uses an HTTPS remote; the broker may use an SSH remote with the
deploy key. Because the broker supplies the URL (4.2), the two never have to
agree, and changing one does not change the other.

**The credential is never in the workspace, never in a command container's
environment, and never returned.** A command that could `env` it would make this
document pointless — that is §2.2's finding, one layer out.

### 4.4 Where it sits

The tool is registered on the existing MCP surface, `/v1/mcp`, which is already
under the bearer gate (`BrokerTokenListener`) — so this adds a capability without
adding an auth surface. It should follow `CommandTools`' registration pattern
exactly, including one detail this repository has already paid for: **the schema
property name and the PHP parameter name must match**, because the SDK resolves
an argument by parameter name with no mapping layer (`CHANGELOG.md`, the
"three MCP tools threw on every call" entry). A `push_branch` whose schema says
`branch` and whose parameter says `$ref` is that bug again.

---

## 5. What must not be trusted from the client

Consolidating §3, because this is the part a future reader will try to
half-implement:

| Input | Trusted? | Why |
|---|---|---|
| The workspace's `.git/config` | **No** | measured to redirect a push (3.2) |
| The workspace's `.git/hooks` | **No** | measured to run and read the credential (3.1) |
| The branch name | validated, then used as a *suffix* | the grammar constrains it to a prefix `AGENTS.md` allows |
| The remote, URL or refspec | **not an input at all** | unexpressible, per 4.1 |

The line to hold: **everything under the workspace is the agent's own working
state, including the parts of it that look like configuration.** A design that
trusts `.git/config` because "it is just where the remote lives" has handed the
destination to the thing being deployed.

---

## 6. What this does and does not buy

**It buys** a push the agent can perform without a secret it can see, with a
destination the agent cannot redirect, a branch policy the tool's grammar makes
unexpressible, and a hook path that is skipped rather than trusted.

**It does not buy** any of the following, and saying so is the point:

- **No content checking.** The broker cannot tell a good commit from a bad one.
  Quality gates stay the agent's responsibility, and the real backstop is CI
  (`.gitea/workflows/tests.yaml`) plus the **server-side pre-receive hook** —
  which is where authority actually lives. The tool's grammar is defence in
  depth, not the boundary, exactly as AGENTS.md's prohibitions are enforced
  server-side rather than by the agent's good behaviour.
- **Not a secret store.** The broker holds one credential for one repository and
  hands it to no one. PLAN.md §6.3 stands: WorkWarp does not hold, index or hand
  out secrets.
- **No PR creation.** Opening a pull request needs the forge's API, which is a
  second capability and a separate decision. Milestone 1 returns the URL and
  stops there.
- **Not a defence against a hostile agent.** An agent that wants to publish
  something can push it to a branch legitimately, or exfiltrate it in a commit
  message. This design constrains *where the credential can go*; it does not
  adjudicate intent, and it should not be described as if it did.

---

## 7. Untested, and therefore not claimed

Stated plainly, because the rest of this document is measured and these lines are
not:

1. **Whether `url.*.insteadOf` rewrites an explicitly-passed URL, and not only a
   remote name.** §3.2 measured the remote-name case. The fix in 4.2 does not
   depend on the answer — it stops consulting the workspace's config altogether —
   but anyone who tries the cheaper `-c remote.origin.pushurl=<url>` override
   *is* depending on it, and should measure before relying.
2. **Whether a scratch clone can push what the workspace has not pushed.** The
   clone has to carry the objects for the revision being pushed; whether it is
   cheaper to clone locally with `--reference`, or to push from the workspace
   with every repo-local key overridden, is an implementation question with a
   measurable answer.
3. **The forge's own behaviour on the far side** — whether the deploy key can
   create a branch, what the pre-receive hook says when it refuses, and what the
   compare URL looks like. None of this was exercised: the measurements above all
   used a local bare repository as the remote.
4. **Timeouts.** An MCP request against `/v1/mcp` idle-timed-out during this
   session's testing. A push is slower than a `git ls-remote`, the broker owns
   the deadline (DOCKER-ACCESS.md §5), and a call that times out may have pushed
   the ref or not — so the report has to admit *both* rather than guess.

---

## 8. Build order

1. **Measure the far side.** A deploy key, one throwaway branch, the pre-receive
   hook's refusal text. Cheap, and it settles §7.3 before anything is designed
   around it.
2. **The push service in the broker**, with the credential and the scratch-clone
   shape from 4.2 — callable, and tested, without any MCP tool in front of it.
3. **The `push_branch` tool**, per 4.4, with the schema/parameter names matched.
4. **The refusal tests**: `main`, `v*`, a path-like branch name, a branch name
   with whitespace, and a rewritten `insteadOf` in the workspace's config. Each
   should fail *before* reaching the forge, and each test should be able to fail.

---

## 9. Decision log

To be merged into `PLAN.md` §14 when the design is settled; recorded here so it
is not lost, without claiming a decision that has not been made.

| Date | Decision |
|---|---|
| 2026-10-15 | Git access is a single `push_branch` tool backed by a broker-held, push-scoped credential. The ssh-key route is rejected on PLAN.md §2.2's own terms: it re-introduces the condition WorkWarp was built to remove. |
| 2026-10-15 | The destination is the broker's to choose, not the client's. Measured: a repo-local `url.*.insteadOf` redirects a push named `origin`, and `--no-verify` does not prevent it — so the URL is not a field, and the workspace's `.git/config` is not trusted input. |
| 2026-10-15 | Hooks are skipped (`--no-verify`) *and* made unreachable (a scratch clone). Measured: a `pre-push` hook runs under the push's credential and reads it. |
