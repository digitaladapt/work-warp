#!/usr/bin/env bash
# probe-git-push.sh — measure how git chooses a push destination, rather than
# trusting the prose in docs/design/GIT-ACCESS.md.
#
# Why it exists (2026-10-15): GIT-ACCESS.md section 7 lists what the design does
# NOT claim because it was not measured, and section 8 makes measuring it the
# first step of the build. The two of those about git's own behaviour need no
# forge and no credential: bare repositories in a scratch directory answer both.
#
#   section 7.1 — does url.*.insteadOf rewrite an EXPLICITLY-PASSED url, or only
#   a remote name? Measured: the url git is handed, command line included — and
#   no command-line -c cancels it, because the repository's config wins. So the
#   cheap `-c remote.origin.pushurl=<url>` route the section hoped for is not
#   merely unmeasured but closed, and worse than it looks: -c does not replace a
#   configured pushurl, it appends to it, so the push fans out to both.
#
#   section 7.2 — can a scratch clone push a revision the workspace has not
#   pushed? Measured: yes. The clone carries the objects, does not inherit the
#   workspace's url.* rules, and --no-verify keeps a hook in the clone itself
#   from running. The scratch clone is the one shape measured to hold.
#
# READ-ONLY with respect to the repository it ships in: it reads nothing from it
# and writes only under SCRATCH. No network, no credential, no forge — every
# remote it uses is a bare repository it created.
#
# Usage:  ./probe-git-push.sh                 # picks its own exec-capable scratch
#         SCRATCH=/some/dir ./probe-git-push.sh
#         KEEP=1 ./probe-git-push.sh          # leave the scratch dir behind
#
# The scratch MUST admit execution, and the script checks before measuring: a
# hook file that cannot be executed is silently ignored by git, so a scratch on a
# noexec mount (this container's /tmp, for instance — see can_exec_in) makes the
# probe assert that hooks do not stop a push, which is false. It refuses instead.
#
# Exit code is the verdict: 0 every check measured as designed, 1 a check
# measured differently (a finding), 2 at least one check could not be made —
# unknown, not clean.
#
# Needs only git and a POSIX shell.

set -uo pipefail

SCRATCH="${SCRATCH:-}"
KEEP="${KEEP:-0}"

FAILED=0
UNKNOWN=0

ok()       { printf '  PASS   %s\n' "$1"; }
bad()      { printf '  FAIL   %s\n' "$1"; FAILED=$((FAILED + 1)); }
unk()      { printf '  ?????  %s\n' "$1"; UNKNOWN=$((UNKNOWN + 1)); }
section()  { printf '\n%s\n' "$1"; }
expect()   { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 — expected [$2], measured [$3]"; fi; }
differs()  { if [ "$2" != "$3" ]; then ok "$1"; else bad "$1 — both are [$2]"; fi; }
contains() { case "$3" in *"$2"*) ok "$1" ;; *) bad "$1 — [$2] absent from [$3]" ;; esac; }
absent()   { case "$3" in *"$2"*) bad "$1 — [$2] present in [$3]" ;; *) ok "$1" ;; esac; }

cleanup() {
  if [ "$KEEP" = 1 ]; then return; fi
  if [ "$FAILED" -eq 0 ] && [ "$UNKNOWN" -eq 0 ]; then
    rm -rf "$SCRATCH"
  else
    printf '  scratch kept for inspection: %s\n' "$SCRATCH"
  fi
}
trap cleanup EXIT

if ! command -v git >/dev/null 2>&1; then
  echo 'git not found: nothing measured'
  exit 2
fi

# A pre-push hook must be *executable* to be a gate, and git silently ignores a
# hook it cannot run. A noexec filesystem therefore makes this probe report the
# false conclusion "hooks do not stop a push" — measured 2026-10-15, when the
# first draft of this script ran its scratch in the workstation container's /tmp
# (a 64 MiB noexec tmpfs, beside a read-only /) and drew exactly that. Pick a
# scratch that can execute, or refuse loudly rather than measure the wrong thing.
can_exec_in() {
  [ -d "$1" ] && [ -w "$1" ] || return 1
  local f="$1/.ww-probe-exec-test"
  printf '#!/bin/sh\nexit 0\n' > "$f" 2>/dev/null || return 1
  chmod +x "$f" 2>/dev/null || { rm -f "$f"; return 1; }
  "$f" >/dev/null 2>&1
  local rc=$?
  rm -f "$f"
  return $rc
}

if [ -n "$SCRATCH" ]; then
  mkdir -p "$SCRATCH" 2>/dev/null
  if ! can_exec_in "$SCRATCH"; then
    printf 'SCRATCH=%s is not writable, or is mounted noexec.\n' "$SCRATCH"
    printf 'Hooks cannot run there, so the hook checks would under-report silently.\n'
    printf 'Nothing was measured: point SCRATCH at an exec-capable directory.\n'
    exit 2
  fi
  BASE="$SCRATCH"
else
  BASE=''
  # /tmp is deliberately absent: on this workstation it is noexec, which is the
  # trap this guard exists for.
  for candidate in "$HOME" "${TMPDIR:-}" /var/tmp /dev/shm; do
    [ -n "$candidate" ] || continue
    if can_exec_in "$candidate"; then BASE="$candidate"; break; fi
  done
  if [ -z "$BASE" ]; then
    printf 'no writable, exec-capable directory found to use as SCRATCH.\n'
    printf 'Nothing was measured.\n'
    exit 2
  fi
fi

SCRATCH="$(mktemp -d -p "$BASE" probe-git-push.XXXXXX)" || exit 2
cd "$SCRATCH" || exit 2
SCRATCH="$(pwd -P)"
printf '  (the scratch admits execution, so a hook can actually run)\n'
printf 'probe-git-push: measuring in %s\n' "$SCRATCH"

# A machine-wide insteadOf rule is the very thing under observation, and a commit
# identity is noise: isolate both. This is what the broker's side of the design
# does too (GIT-ACCESS.md 4.2 step 2), so the measurement matches the shape it
# defends.
export GIT_CONFIG_GLOBAL=/dev/null
export GIT_CONFIG_SYSTEM=/dev/null
export GIT_TERMINAL_PROMPT=0
export GIT_AUTHOR_NAME=probe GIT_AUTHOR_EMAIL=probe@localhost
export GIT_COMMITTER_NAME=probe GIT_COMMITTER_EMAIL=probe@localhost

GOOD="$SCRATCH/good.git"
EVIL="$SCRATCH/evil.git"
git init -q --bare good.git
git init -q --bare evil.git
heads() { git --git-dir="$SCRATCH/$1.git" for-each-ref --format='%(refname:short)' refs/heads | xargs echo; }
mkrepo() { git init -q "$1"; ( cd "$1" && git config user.name probe && git config user.email probe@localhost && echo "$2" > f && git add f && git commit -qm "$2" && git rev-parse HEAD ); }

section '7.1  can the workspace choose the destination behind the pusher?'
SHA="$(mkrepo "$SCRATCH/work" initial)"
cd "$SCRATCH/work" || exit 2
if [ -z "$(git remote)" ]; then ok 'the repository has no remote at all'; else bad 'a remote exists: not the measurement intended'; fi

git push --no-verify "$GOOD" "$SHA:refs/heads/direct" >/dev/null 2>&1
contains 'explicit url, nothing rewritten: the ref lands where it was sent' direct "$(heads good)"
absent   'explicit url, nothing rewritten: nothing lands elsewhere'         direct "$(heads evil)"

git config url."$EVIL".insteadOf "$GOOD"
printf '  one line of config added — still no remote anywhere:\n'
git config --get-regexp '^url\.' | sed 's/^/    /'
git push --no-verify "$GOOD" "$SHA:refs/heads/rewritten" >/dev/null 2>&1
contains 'an EXPLICIT url is rewritten by url.*.insteadOf   (answers 7.1)' rewritten "$(heads evil)"
absent   'and the url that was actually named never sees it'               rewritten "$(heads good)"

git -c url."$EVIL".insteadOf= push --no-verify "$GOOD" "$SHA:refs/heads/cancel" >/dev/null 2>&1
contains '-c url.<evil>.insteadOf= does NOT cancel the rewrite' cancel "$(heads evil)"

section '7.1  the cheap route: is -c remote.origin.pushurl=<url> a guard?'
cd "$SCRATCH" || exit 2
git init -q --bare srv.git
git init -q --bare evil2.git
SHA2="$(mkrepo "$SCRATCH/nrt" second)"
cd "$SCRATCH/nrt" || exit 2
git remote add origin "$SCRATCH/srv.git"
git config remote.origin.pushurl "$SCRATCH/evil2.git"
expect 'a plain repo config defines a remote, no git remote add needed' "$SCRATCH/srv.git" "$(git remote get-url origin)"
expect 'and a pushurl shadowing it sits in that same config'              "$SCRATCH/evil2.git" "$(git config remote.origin.pushurl)"

git push --no-verify origin "$SHA2:refs/heads/plain" >/dev/null 2>&1
contains 'unguarded, the push obeys the shadowing pushurl' plain "$(heads evil2)"

git -c remote.origin.pushurl="$SCRATCH/srv.git" push --no-verify origin "$SHA2:refs/heads/over" >/dev/null 2>&1
contains '-c pushurl=<good> does reach the good repository' over "$(heads srv)"
contains '...but does NOT replace the shadowed one: it fans out to both' over "$(heads evil2)"

git -c remote.origin.pushurl= push --no-verify origin "$SHA2:refs/heads/empty" >/dev/null 2>&1
contains '-c pushurl= (empty) falls back to the url — good, so far' empty "$(heads srv)"

git config url."$SCRATCH/evil2.git".insteadOf "$SCRATCH/srv.git"
git -c remote.origin.pushurl= push --no-verify origin "$SHA2:refs/heads/both" >/dev/null 2>&1
contains 'with 3.2 also in play, even that lands at the rewrite target' both "$(heads evil2)"

section '7.2  can a scratch clone push a revision the workspace has not pushed?'
cd "$SCRATCH" || exit 2
git init -q --bare srv2.git
git init -q --bare evil3.git
mkrepo "$SCRATCH/ws" first >/dev/null
cd "$SCRATCH/ws" || exit 2
echo second > d
# a commit that exists ONLY in the workspace: nothing here has been fetched, so a
# clone that carries it must have carried the objects itself.
git add d
git commit -qm second
SHA4="$(git rev-parse HEAD)"
# now the workspace acquires exactly the two things section 3 measured, both in
# its own .git: a rule that redirects the destination, and a hook that runs.
git config url."$SCRATCH/evil3.git".insteadOf "$SCRATCH/srv2.git"
git remote add origin "$SCRATCH/srv2.git"
printf '#!/bin/sh\nprintf ran > "%s/ws-hook-marker"\nexit 1\n' "$SCRATCH" > .git/hooks/pre-push
chmod +x .git/hooks/pre-push
printf '  workspace: unpushed %s, a redirect rule, and a pre-push hook\n' "${SHA4:0:7}"

cd "$SCRATCH" || exit 2
if git clone -q --no-local "$SCRATCH/ws" "$SCRATCH/clone" 2>/dev/null; then
  ok 'a scratch clone of the workspace succeeds'
  cd "$SCRATCH/clone" || exit 2
  if git cat-file -e "$SHA4^{commit}" 2>/dev/null; then
    ok 'the clone carries the unpushed revision (the object came with it)'
  else
    unk 'the clone does not carry the unpushed revision'
  fi
  RULES="$(git config --get-regexp '^url\.' | xargs echo)"
  absent 'the clone does not inherit a single url.* rule from the workspace' 'insteadof' "$(echo "$RULES" | tr 'A-Z' 'a-z')"
  git push --no-verify "$SCRATCH/srv2.git" "$SHA4:refs/heads/via-clone" >/dev/null 2>&1
  contains 'a push from the clone lands at the url it was given' via-clone "$(heads srv2)"
  absent   'the workspace rule does not redirect it to the rewrite target' via-clone "$(heads evil3)"
  if [ -e "$SCRATCH/ws-hook-marker" ]; then
    bad 'the workspace pre-push hook ran during the clone push'
  else
    ok 'the workspace hook never runs: it is unreachable from the clone'
  fi
  printf '#!/bin/sh\nprintf ran > "%s/clone-hook-marker"\nexit 1\n' "$SCRATCH" > .git/hooks/pre-push
  chmod +x .git/hooks/pre-push
  if git push "$SCRATCH/srv2.git" "$SHA4:refs/heads/no-flag" >/dev/null 2>&1; then
    bad 'a hook in the clone did not stop the push without --no-verify'
  else
    ok 'a hook in the clone DOES stop the push — so --no-verify is load-bearing'
  fi
  absent 'without the flag the ref is not created: the hook is a real gate' no-flag "$(heads srv2)"
  git push --no-verify "$SCRATCH/srv2.git" "$SHA4:refs/heads/with-flag" >/dev/null 2>&1
  contains '--no-verify gets past that same hook' with-flag "$(heads srv2)"
  CLONE_HEAD="$(git rev-parse HEAD)"
  cd "$SCRATCH/ws" || exit 2
  echo third > e
  git add e
  git commit -qm third
  WS_HEAD="$(git rev-parse HEAD)"
  expect 'the clone is a snapshot: later workspace commits are invisible to it' "$SHA4" "$CLONE_HEAD"
  differs '...and the workspace really did move on, so the snapshot differs' "$CLONE_HEAD" "$WS_HEAD"
else
  unk 'the scratch clone could not be made'
fi

section '7.2  and the cheaper --reference variant?'
cd "$SCRATCH" || exit 2
for variant in plain nolocal; do
  case "$variant" in
    plain)   REF_ARGS='';;
    nolocal) REF_ARGS='--no-local';;
  esac
  # shellcheck disable=SC2086
  if git clone -q $REF_ARGS --reference "$SCRATCH/ws" "$SCRATCH/ws" "$SCRATCH/ref-$variant" 2>/dev/null; then
    cd "$SCRATCH/ref-$variant" || exit 2
    if [ -n "$(git config --get-regexp '^url\.')" ]; then
      bad "the $variant reference clone inherited a url.* rule"
    else
      ok "the $variant reference clone inherits no url.* rule of its own"
    fi
    git push --no-verify "$SCRATCH/srv2.git" "$SHA4:refs/heads/ref-$variant" >/dev/null 2>&1
    contains "a $variant reference-clone push lands at the url it was given" "ref-$variant" "$(heads srv2)"
    absent   "and the workspace rule does not redirect the $variant clone" "ref-$variant" "$(heads evil3)"
    cd "$SCRATCH" || exit 2
  else
    unk "the $variant reference clone could not be made"
  fi
done

section 'verdict'
if [ "$FAILED" -eq 0 ] && [ "$UNKNOWN" -eq 0 ]; then
  echo '  every check measured as GIT-ACCESS.md section 4.2 describes it'
  exit 0
fi
printf '  %s check(s) measured differently, %s could not be made\n' "$FAILED" "$UNKNOWN"
[ "$FAILED" -gt 0 ] && exit 1
exit 2
