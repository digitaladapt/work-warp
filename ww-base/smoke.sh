#!/usr/bin/env bash
# ww-base/smoke.sh — the command image's contract, checked against a real daemon.
#
# This is the half of the image's quality gate that CI cannot run: the suite in
# this repo asserts what the broker *builds*, never what it *reaches*
# (CONTRIBUTING.md), and no daemon is anywhere in it. What only a daemon can
# answer is checked here:
#
#   1. the image's shape — uid 1000 by default, no default command, no HOME in
#      its config (the runtime derives $HOME from the passwd entry, which must
#      name /workspace — see below);
#   2. the toolset is what the Dockerfile promises;
#   3. **a fresh volume mounted at /workspace is writable by uid 1000.** This
#      is the load-bearing one. It depends on the image shipping /workspace
#      owned by 1000:1000 and on the daemon's volume population copying that
#      ownership onto the empty volume (containerd fs.copyFileInfo). Nothing
#      inside the container can fix it at runtime: the root filesystem is
#      read-only and the process has no capabilities, which is why the failure
#      would be a session's first command dying on a permission error that
#      looks like a broker bug.
#
# The run in (3) uses the production shape — --read-only, dropped caps,
# --no-new-privileges, --network none, uid 1000 — so an image that secretly
# needs a writable rootfs fails here rather than in a session.
#
# The HOME check is subtler than it looks: an image cannot "leave HOME unset".
# runc derives one from the container's passwd entry whenever the environment
# carries none, so what is checked is (a) the image config sets none, and
# (b) the derived one is /workspace — the only directory a command can write
# under the production shape.
#
# Usage:
#   bash ww-base/smoke.sh [image]
#
# Default image: digitaladapt/work-warp:develop-base — the tag a push to main
# publishes. Releases also publish :latest-base and :<version>-base. Pass a
# locally built tag when testing a change before it is pushed.
#
# Exit code is the verdict: 0 all checks passed, 1 something failed.

set -euo pipefail

IMAGE="${1:-digitaladapt/work-warp:develop-base}"

failures=0
cleanup_volume=""

pass() { printf 'ok      %s\n' "$*"; }
fail() { printf 'FAIL    %s\n' "$*"; failures=$((failures + 1)); }
die() { printf 'ww-base-smoke: %s\n' "$*" >&2; exit 1; }
note() { printf 'note    %s\n' "$*"; }

cleanup() {
    if [ -n "$cleanup_volume" ]; then
        docker volume rm "$cleanup_volume" >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT

command -v docker >/dev/null 2>&1 || die "docker is not installed — this script needs a real daemon and cannot run in CI"

if ! docker info >/dev/null 2>&1; then
    die "no reachable Docker daemon. This script is meant for the machine that has one."
fi

if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
    die "image '$IMAGE' not found locally. Pull it, or pass a locally built tag."
fi

printf '\n== %s ==\n\n' "$IMAGE"

# ---------------------------------------------------------------------------
# 1. The image's shape. Read the config, then assert the refusal behaviourally.
# ---------------------------------------------------------------------------

entrypoint="$(docker image inspect --format '{{json .Config.Entrypoint}}' "$IMAGE")"
cmd="$(docker image inspect --format '{{json .Config.Cmd}}' "$IMAGE")"
user="$(docker image inspect --format '{{.Config.User}}' "$IMAGE")"
workdir="$(docker image inspect --format '{{.Config.WorkingDir}}' "$IMAGE")"

# An empty value serialises as either null or [] depending on how it got that
# way; both mean "nothing", and neither is a command.
for pair in "Entrypoint:$entrypoint" "Cmd:$cmd"; do
    name="${pair%%:*}"
    value="${pair#*:}"
    if [ "$value" = "null" ] || [ "$value" = "[]" ]; then
        pass "$name is empty — nothing runs by default"
    else
        fail "$name is $value; it must be empty (no ENTRYPOINT, CMD [] clears the base image's bash)"
    fi
done

if [ "$user" = "1000:1000" ]; then
    pass "default user is 1000:1000 (numeric, matching the broker's User)"
else
    fail "default user is '$user', expected '1000:1000'"
fi

if [ "$workdir" = "/workspace" ]; then
    pass "default working directory is /workspace"
else
    fail "default working directory is '$workdir', expected '/workspace'"
fi

# The config is where "the image must not set HOME" is decided, and the one
# half of the HOME story this file controls directly: the environment is the
# broker's to pass per request. What the config carries is checked here; what
# the runtime derives from the passwd entry is checked behaviourally below.
config_env="$(docker image inspect --format '{{range .Config.Env}}{{println .}}{{end}}' "$IMAGE")"

if printf '%s\n' "$config_env" | grep -q '^HOME='; then
    fail "the image config sets HOME; the environment is the broker's to pass, and the image must not set one"
else
    pass "the image config carries no HOME — the broker decides the environment; the runtime's derived default comes from the passwd entry"
fi

# The decisive shape check is behavioural: a bare create must be refused for
# the right reason. If the image carried any default command, this succeeds and
# something would run that nobody asked for.
if created="$(docker create "$IMAGE" 2>&1)"; then
    fail "docker create with no command succeeded — the image carries a default command"
    docker rm "$created" >/dev/null 2>&1 || true
elif printf '%s' "$created" | grep -qi 'no command specified'; then
    pass "a bare 'docker create' is refused ('no command specified') — the command is the caller's, always"
else
    fail "docker create failed, but not because a command was missing: $created"
fi

# ---------------------------------------------------------------------------
# 2. The toolset, the environment it promises, and /workspace's ownership in
#    the image itself.
#
# The tool list here mirrors the Dockerfile's list: if a package is dropped
# from the image, this is where that becomes visible.
# ---------------------------------------------------------------------------

if docker run --rm "$IMAGE" bash -euc '
    [ "$(id -u)" = "1000" ] || { echo "running as uid $(id -u), expected 1000"; exit 1; }

    for t in bash git curl wget openssl jq less file rsync unzip zip zstd xz bzip2 tar gzip \
             make patch diff python3 ip dig ping ps stat sed awk grep find bc xxd; do
        command -v "$t" >/dev/null || { echo "missing tool: $t"; exit 1; }
    done

    # The full standard library, not python3-minimal: the pieces a script
    # reaches for first are exactly the ones that split out of it.
    python3 -c "import ssl, sqlite3, ctypes, http.client, json, venv" \
        || { echo "python3 is missing standard-library modules (was it python3-minimal?)"; exit 1; }

    # The image config sets no HOME (checked above); the runtime derives one
    # from the passwd entry inside the container when the environment carries
    # none. The entry must name /workspace — the one directory a command may
    # write — or every tool that writes to $HOME dies on the read-only rootfs
    # under the production shape.
    [ "${HOME:-}" = "/workspace" ] \
        || { echo "HOME is \"${HOME:-<unset>}\", expected /workspace — runc derives HOME from the passwd entry, and it must name the writable directory"; exit 1; }

    [ "$(stat -c "%u:%g" /workspace)" = "1000:1000" ] \
        || { echo "/workspace is $(stat -c "%u:%g" /workspace), expected 1000:1000"; exit 1; }

    echo "toolset, environment and ownership ok"
'; then
    pass "every expected tool resolves; python3 has its stdlib; HOME derives to /workspace; /workspace is 1000:1000"
else
    fail "the toolset, environment or ownership check failed (output above)"
fi

# The documented escape hatch: a caller that wants a different HOME passes one
# in the request's env, and the runtime keeps a value that is already set. If
# this ever stops holding, the README's advice stops being true.
if docker run --rm -e HOME=/tmp "$IMAGE" bash -euc '[ "$HOME" = "/tmp" ]'; then
    pass "a caller-supplied HOME (/tmp) wins over the runtime's derived default"
else
    fail "a caller-supplied HOME did not survive; the documented escape hatch is broken"
fi

# ---------------------------------------------------------------------------
# 3. The production shape, against a fresh volume. This is the check that
#    matters most: read-only rootfs, dropped caps, no network, and a volume
#    the daemon must have made writable for uid 1000 via volume population.
# ---------------------------------------------------------------------------

cleanup_volume="ww-smoke-$$"
docker volume create "$cleanup_volume" >/dev/null

if docker run --rm \
        --init \
        --read-only \
        --tmpfs "/tmp:rw,noexec,nosuid,size=64m" \
        --cap-drop=ALL \
        --security-opt=no-new-privileges \
        --network none \
        --user 1000:1000 \
        -v "$cleanup_volume:/workspace" \
        "$IMAGE" bash -euc '
    # The volume root must be writable by this uid — this is what volume
    # population carries over from the image. Nothing here can chown.
    touch /workspace/.smoke-write && rm /workspace/.smoke-write
    mkdir -p /workspace/.smoke-dir && rmdir /workspace/.smoke-dir

    # And HOME must point at it: runc derives HOME from the passwd entry, and
    # the first write to $HOME that matters (git config --global, ssh
    # known_hosts) has to land somewhere writable.
    [ "${HOME:-}" = "/workspace" ] || { echo "HOME is \"${HOME:-<unset>}\", expected /workspace"; exit 1; }
    touch "$HOME/.smoke-home-write" && rm "$HOME/.smoke-home-write"

    # /tmp is the one guaranteed scratch space under a read-only rootfs.
    touch /tmp/.smoke-write && rm /tmp/.smoke-write

    # The rootfs is genuinely read-only in this shape; if it were writable,
    # the run above proves less than it claims.
    if mkdir /usr/local/.smoke-should-fail 2>/dev/null; then
        echo "rootfs is writable; the production shape is --read-only"; exit 1
    fi

    echo "fresh volume writable by uid 1000 under the production shape"
'; then
    pass "fresh volume: /workspace is writable by 1000:1000 (and HOME derives to it) with a read-only rootfs and dropped caps"
else
    fail "a fresh volume was not writable at /workspace under the production shape"
    note "check that the image ships /workspace owned by 1000:1000 (see ww-base/Dockerfile) and that the daemon populates volumes"
fi

# ---------------------------------------------------------------------------
# Verdict.
# ---------------------------------------------------------------------------

printf '\n'
if [ "$failures" -eq 0 ]; then
    printf 'all checks passed\n'
else
    printf '%d check(s) failed\n' "$failures"
    exit 1
fi
