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

**Extended again, on the far side (§11.2): the redirect also misdirects the
read-back.** Measured against the real forge, `git ls-remote <intended>` issued
from inside the poisoned workspace reported the branch as present when it was
only ever written to the *other* repository. So a tool that pushes and then
`ls-remote`s to confirm what landed is not checking anything — both halves
resolve the url through the same poisoned rule. The verification has to run where
the rule is not, which is what §4.2's scratch clone gives it.

**Extended, same day: an explicitly-passed URL is rewritten too.** The open
question in §7 listed it; `bin/probe-git-push.sh` (§10) answers it. Given a
repository with *no remote at all* — so nothing can be attributed to remote-name
resolution — and one line of config:

```
$ git config url."/scratch/evil.git".insteadOf "/scratch/good.git"
$ git push --no-verify /scratch/good.git HEAD:refs/heads/a
To /scratch/evil.git
 * [new branch]      HEAD -> a          # and /scratch/good.git never sees it
```

The URL that was actually named is not consulted. This is the load-bearing detail
for anyone tempted by the cheap route: a guard built on `-c url.<good>.insteadOf=`
is **not** a guard, because the repository's own rules are merged with it and the
longest match still wins. The same defeats the cheapest idea of all, an empty
`-c remote.origin.pushurl=` to void a shadowing pushurl: with no rule present the
empty pushurl already falls back to the remote's url, which lands at the rewrite
target — and a *non-empty* override does not replace the shadowed pushurl at all,
it fans out to both destinations. Measured, all of it, in the same probe.

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
   client's `url.*.insteadOf` are never consulted. That is the fix for §3.2, and it
   is a fix by construction rather than by checking — which matters more than it
   first looked, because §10 measured that the rewrite rule applies to an
   *explicitly-passed* URL as well. There is no override flag that declines it; the
   only way to not be rewritten is to not read the config. **Confirmed on the far
   side (§11.2):** the naive push landed in the wrong repository, and a
   post-push `ls-remote` from the workspace confirmed it as though it were right.
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

The forms above are still right, and this paragraph is still **aspiration, not
measurement**. §11.3 puts the key in `~/.ssh/id_ed25519` with `$HOME` set to
the workspace and shows a `pre-push` hook copying it out byte-identically. The
broker holding the credential is not sufficient while the command shares a UID
and a `$HOME` with it — the key has to live somewhere the command cannot read.
That is §7.3, and it is the design's largest open item.

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
not. Two questions that used to live here are now measured — §10 for git's own
behaviour, §11 for the real forge — and are struck from the list rather than
quietly dropped.

1. **Branch protection on the far side, if any.** §11.1 shows this key can update
   `main`, create `master`, force-push and delete on a repository it created, so
   "non-main branches only" is policy, not mechanism. Whether `public/work-warp`
   enforces it is **not measured**, and it cannot be settled with the tools this
   session has. `git push --dry-run` is not a probe for it: measured, it never
   sends update commands at all, so it reports success for things the far side may
   well refuse — it exited 0 announcing a forced update, and exited 0 announcing
   `- [deleted] definitely-not-there` for a branch that does not exist. A server-side
   refusal is only visible by actually attempting the write, and doing that to
   `main` is the thing being asked about. One branch-protection API call with a
   token settles it.
2. **The MCP deadline, on a push.** A push to this forge is ~1.0–1.7s for one ref
   (§11.1), which is comfortable — but an MCP request against `/v1/mcp`
   idle-timed-out during this session's testing, the broker owns the deadline
   (DOCKER-ACCESS.md §5), and a call that times out may have pushed the ref or not.
   §11.1 gives the recovery its shape: a repeat push is `Everything up-to-date` at
   exit 0, so the honest report is "pushed, or already there" rather than a guess.
3. **The broker's own credential placement.** §11.3 shows the private key is
   readable at `$HOME/.ssh/id_ed25519`, where `$HOME` *is* the workspace. Until the
   broker holds the key somewhere the command cannot read, "the broker holds the
   credential" describes who *runs* git, not who can *take* the key.

---

## 8. Build order

1. ~~**Measure the far side.**~~ **Done, 2026-10-15 — §11.** It did not settle
   the question the way this step expected: there is no pre-receive refusal to
   learn the text of, because the key is not limited to non-main branches at all.
   What it did settle is that §4.2's scratch-clone shape survives a real forge,
   that the redirect in §3.2 poisons the read-back too, and that §4.3's claim
   about the credential is not yet true (§11.3). One question is left over and
   moved to §7.1: whether *this* repository enforces a branch rule server-side.
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
| 2026-10-15 | The `-c` overrides are rejected as guards. Measured (§10): a repo-local rule rewrites an *explicitly-passed* URL, so `-c url.<good>.insteadOf=` cannot cancel it; and `-c remote.origin.pushurl=` either fans out to both destinations or falls back to a url that is itself rewritten. The broker must not read the workspace's config. |
| 2026-10-15 | The scratch-clone shape is confirmed rather than assumed. Measured (§10): a `--no-local` clone carries an unpushed revision's objects, inherits no `url.*` rule, and cannot reach the workspace's hooks — so `--no-verify` in 4.2 point 4 is belt-and-braces, not the mechanism. |
| 2026-10-15 | The far side is measured (§11). The key is **not** restricted to non-main branches: on a repository it created it updated `main`, created `master`, force-pushed and deleted, all at exit 0. "Non-main only" is per-repository policy, so the tool must state and enforce the branch rule itself rather than inherit it. |
| 2026-10-15 | §4.2 holds against a real forge, and §3.2 is worse than written: a repo-local `url.*.insteadOf` misdirects the **read-back** as well as the write, so verification cannot be a post-push `ls-remote` from the workspace. The scratch clone (source as a *path*, not a url) is what makes the destination a thing the tool names rather than a thing the workspace answers. |
| 2026-10-15 | The compare URL is the forge's, not git's — Gitea writes it to the remote's stderr on a new branch. Either scavenge that stream or build the url from base_url + owner + repo + branch; recorded as an open design choice, not decided here. |
| 2026-10-15 | §4.3 is **not yet true**. Measured (§11.3): the key is readable at `$HOME/.ssh/id_ed25519` and `$HOME` is the workspace, so one `pre-push` hook copies it out byte-identically. The broker must hold it where the command cannot read it, or "the broker holds the credential" means only who runs git. |
| 2026-10-15 | `git push --dry-run` is not usable as a pre-flight check for a server-side refusal. Measured (§7.1): it exits 0 announcing a force-push, and exits 0 announcing the deletion of a branch that does not exist. |

---

## 10. The probe

`bin/probe-git-push.sh` measures §3's claims and the questions §7 used to carry. It
is cheap, self-contained and safe to re-run: everything happens inside a scratch
directory of local bare repositories and nothing contacts the forge. A clean pass
exits 0 and prints the verdict the claims above rest on; a check that measures
differently exits 1 and keeps the scratch for inspection; a check that could not be
made at all exits 2 and counts as unknown, never as a pass.

What it settled:

1. **`url.*.insteadOf` rewrites an explicitly-passed URL, not only a remote name**
   (§3.2). The cheap `-c remote.origin.pushurl=<url>` guard is therefore a dead end,
   and 4.2's "supply the URL ourselves, from a config we wrote" is doing real work.
2. **A scratch clone carries what the workspace has not pushed, and inherits nothing
   that matters.** The clone carries the unpushed revision's objects itself, inherits
   no `url.*` rule, and never runs the workspace's hooks; a push from it lands where
   it is told. `--reference` variants behave the same way, so the cheaper form is
   available if copying objects ever matters — note `git clone --reference <src>
   <src> <dst>` (the source appears twice) still contacts the source to fetch refs,
   so "no local transport" and "borrow objects" are not the same request.

And one trap, recorded because it produced a *wrong* answer before it produced the
right one:

3. **The scratch directory must admit execution.** `pre-push` is a gate only if the
   hook file can be executed, and git silently ignores a hook it cannot run. The
   first draft of the probe ran its scratch in the command container's `/tmp` —
   `tmpfs rw,nosuid,nodev,noexec` — and duly reported "a hook in the clone did not
   stop the push". The hook was fine; the filesystem was `noexec`. Re-measuring
   under a probe that checks for an exec-capable scratch showed the hook *does* stop
   the push (and that without `--no-verify` the ref is never created), which is what
   makes 4.2 point 4 load-bearing for the broker's own clone too.

The probe now refuses to measure rather than measure that: a verdict that can be
wrong because of the filesystem the probe chose is worse than no probe, so the
`can_exec_in` guard is the point of this section and not a footnote to it.

Not measured here, because it needs a key and a forge: anything on the far
side. §11 measures that by hand, and says so.

## 11. The far side — measured against the real forge (2026-10-15)

§3 was measured against a local bare repository. This section is the same claims
against `code.devgnome.com`, with a real key. It is the exercise §8's build order
asked for, and it changed two things: it strengthened §4.2 with a new failure
mode, and it weakened a claim §1 was relying on.

The key is `~/.ssh/id_ed25519`, of which more in §11.3 — `$HOME` here is
`/workspace`, so the phrase "in the standard place" and the phrase "inside the
command's reach" are the same address.

### 11.1 What the forge does

Each line below is a push that was actually made, to a repository this key
created (the auto-create path is the first finding).

1. **A push to a repository that does not exist creates it.**

   ```
   $ git push git@code.devgnome.com:lyra/ww-farside-probe.git main
   To code.devgnome.com:lyra/ww-farside-probe.git
    * [new branch]      main -> main
   ```

   The namespace is not a hint: the same name under `public/` is a *different*
   repository, and does not exist — `lyra/` is where the key lands. That makes the
   destination two variables, not one: namespace *and* name, both of which the
   tool must supply rather than accept.
2. **A branch that does not exist is created, and the forge prints the compare
   URL on the remote's stderr:**

   ```
   remote: Create a new pull request for 'probe/feature-1':
   remote:   https://code.devgnome.com/lyra/ww-farside-probe/pulls/new/probe/feature-1
   ```

   That URL is the whole of the function's value to the user, and it is *not*
   local to the push — git does not synthesise it. It has to be scavenged from the
   remote's stderr, which means the broker has to keep that stream and parse it,
   or build the same URL itself from `base_url + owner + repo + branch`. The second
   is more honest about what it is; the first is more likely to survive a Gitea
   upgrade. Recorded as a design choice, not decided here.
3. **The key is not restricted to non-main branches.** On a repository it created,
   the same key fast-forwarded `refs/heads/main`, created a *new* branch named
   `master`, **force-pushed** over `main`, and **deleted** `refs/heads/main-2` —
   every one at exit 0. So "you have an ssh key which can push to non-main
   branches" is true of the repositories someone has configured, not of the key.
   A tool that trusts the key for that property is trusting a setting it cannot
   see. §7.1.
4. **A push is idempotent.** Re-pushing an up-to-date ref prints
   `Everything up-to-date` and exits 0 — the property the timeout story in §7.2
   needs, and it holds.
5. **Timing**: ~1.0–1.7s per push for a single ref, over three runs.

The refusals the tool has to report, all measured, and all distinguishable:

| Attempt | What git prints | Exit |
|---|---|---|
| Non-fast-forward, over an existing branch | `! [rejected] ... (non-fast-forward)` | 1 |
| Malformed ref name (`probe/bad..name`) | `fatal: invalid refspec '...'` | 128 |
| Repo in a namespace the key does not own | `error: Cannot find repository someone-else/nope` | 128 |

The first two are the tool's own validation failing (exit 128 is *before* any
contact with the forge — the refspec never parsed), and the third is the forge
speaking. They must not be reported as one thing: "the branch is behind, push
again after integrating" is advice, and "that is not a repository you can write
to" is not.

### 11.2 §4.2 holds — and §3.2 has a second edge

This is the experiment the design turns on, run with a real key, a real forge, and
a workspace deliberately poisoned the way §3.2 describes.

Setup: a real clone of a real repository on the forge; one line of untrusted
config, `url.<evil>.insteadOf <intended>`; an unpushed commit on a branch.

**The naive push fails, and fails while reporting success:**

```
$ git push --no-verify git@...:lyra/ww-farside-probe.git work:refs/heads/work
To code.devgnome.com:lyra/ww-farside-evil.git            # <- not the url it was given
 * [new branch]      work -> work

# verified from a tree with no url.* rule:
  ww-farside-probe.git   does NOT have refs/heads/work
  ww-farside-evil.git    HAS refs/heads/work
```

and, in the same run, `git ls-remote <intended>` issued *from inside the poisoned
workspace* reported the branch as present. So the redirect does not merely
misroute the write: **it misdirects the read-back the tool would use to verify the
write.** A naive "push, then `ls-remote` to confirm which sha landed" is not a
check — it is a second statement of the same false claim, because both halves
resolve the url through the same poisoned rule. This was not in §3.2, which only
claimed the write; it is the stronger form.

**§4.2's shape survives it**, with the workspace still poisoned:

```
$ git clone /workspace/untrusted /workspace/scratch    # the workspace, as a PATH
$ cd /workspace/scratch && git push --no-verify <intended> HEAD:refs/heads/work
To code.devgnome.com:lyra/ww-farside-probe.git
 * [new branch]      HEAD -> work

# verified from a clean tree:
  ww-farside-probe.git   HAS refs/heads/work   (810a85a...)
  ww-farside-evil.git    does NOT have it
```

Two details make that work, and both are load-bearing:

- **The clone source is a path, not a url.** No `url.*` rule can match
  `/workspace/untrusted`, and no transport or remote helper is involved. This is
  why cloning *the workspace* is sufficient and `--reference <url>` on the far
  side is not the same request.
- **The clone carries no `url.*` rule and no usable remote.** Measured: the
  scratch's config has no `url.*` entry, and its only remote is `origin ->
  /workspace/untrusted`, which is not the forge. The tool then names the
  destination itself, and a brand-new clone has no local rule to answer with.

Confirmed at the same time: the scratch clone carries the unpushed commit
(`810a85a`), clones with no `--no-local` because the source is already local, and
never runs the workspace's hooks.

### 11.3 A hook in the workspace runs under the push — with the key in reach

§3.1 claimed a `pre-push` hook runs under whatever credential the push holds and
reads it. Against the real forge, with the real key, that is exactly what happened.
An untrusted workspace planted this in `.git/hooks/pre-push`, and it was invoked by
a push **from that workspace**:

```
HOOK RAN: pre-push
HOOK cwd=/workspace/farside/untrusted
HOOK can read the private key: YES
HOOK copied it to /workspace/farside/stolen_key      # byte-identical to the original
HOOK HOME=/workspace user=user
```

The same push with `--no-verify` did not run the hook. So §4.2 point 4
(`--no-verify`) and §4.2 point 3 (the scratch clone, which never sees the
workspace's hooks) are each sufficient, and are kept together because the failure
they prevent is silent.

The uncomfortable part is `$HOME=/workspace`. A `pre-push` hook needs only
`cp $HOME/.ssh/id_ed25519 ...` — one line, no exploit, no elevation — and this
document's own §4.3 story about a broker-held credential is defeated by it. The
key is held *by* the broker and readable *by* the command, and those must not stay
the same thing if §4.3 is to mean anything. The key belongs somewhere the command
container cannot mount or read, and neither the design nor this measurement makes
that true yet. §7.3.

### 11.4 What the probe can and cannot now answer

`bin/probe-git-push.sh` stays offline and credential-free on purpose: it is the
part of this discipline that any contributor, in any environment, can re-run
without a key or a forge. The far side is measured by hand above, and the honest
thing to do with a hand measurement is to say so. A `probe-git-farside.sh` that
pushes to a scratch repository is worth having; it is not written, and §11's
numbers should be treated as one session's, not as a re-runnable check.

---

