#!/usr/bin/env bash
# probe-guardrails.sh — measure what the Docker proxy actually admits.
#
# Run this FIRST, once the stack is up, before trusting any of the prose.
# I could not verify the proxy's behaviour from inside the sandbox (there was
# no daemon to test against), so `POST: 1` in deploy/compose.yaml is an
# informed guess rather than a measurement. This script replaces the guess with
# an answer.
#
# It is READ-MOSTLY BY DESIGN. It creates one container and removes it. It does
# not start anything long-lived, touch a network, or look at a neighbour.
#
# Usage:  ./probe-guardrails.sh            (uses $DOCKER_HOST)
#         ./probe-guardrails.sh --with-create   also test the create path
#
# Needs `curl`. It no longer uses `docker api` — see raw_api below for why that
# mattered. Set WW_API_PREFIX=/v1.44 to pin an explicit API version in the path.

set -uo pipefail

WITH_CREATE=0
[ "${1:-}" = "--with-create" ] && WITH_CREATE=1

pass() { printf '  \033[32mok\033[0m      %s\n' "$1"; }
fail() { printf '  \033[31mREACHED\033[0m %s\n' "$1"; PROBLEMS=$((PROBLEMS+1)); }
info() { printf '  ----    %s\n' "$1"; }
PROBLEMS=0
# Endpoints we POSITIVELY reached. A probe that cannot see a single success
# cannot tell "blocked" from "could not ask" — see the gate before the summary.
POSITIVE=0
# Path prefix for API calls. Empty by default: the daemon accepts unversioned
# paths, and the proxy's section filters match the path as written, so the
# unversioned form is the one least likely to be wrongly filtered either way.
API_PREFIX="${WW_API_PREFIX:-}"

LAST_CODE=000
# Raw request against the Docker API, replacing `docker api`.
#
# `docker api` is NOT a subcommand of the Docker CLI. Verified against 29.8.0:
# `docker api /version` exits 1 with "unknown command". The original script used
# it throughout, so EVERY endpoint — readable and forbidden alike — came back
# non-zero, every forbidden path printed "blocked", and the probe reported a
# clean board no matter what the proxy actually admitted. It could not fail.
#
# $1 = method, $2 = path. Returns 0 only on HTTP 2xx/3xx; LAST_CODE holds the
# status either way, so a refusal reads as 403 (the proxy denied it) rather than
# 000 (nothing answered) — a distinction the old script threw away.
raw_api() {
  local method="$1" path="$2" host
  LAST_CODE=000
  [ -n "${DOCKER_HOST:-}" ] || return 1
  host="${DOCKER_HOST#tcp://}"
  host="${host%%/*}"
  LAST_CODE="$(curl -s -o /dev/null -w '%{http_code}' -m 5 \
      -X "$method" "http://${host}${API_PREFIX}${path}" 2>/dev/null || echo 000)"
  case "$LAST_CODE" in 2*|3*) return 0 ;; *) return 1 ;; esac
}

printf '\nDocker endpoint: %s\n\n' "${DOCKER_HOST:-<unset>}"

if ! docker info >/dev/null 2>&1; then
  printf '  \033[31mNo daemon reachable.\033[0m Nothing to probe.\n\n'
  exit 1
fi

printf 'READ paths\n'
for ep in version info containers/json images/json networks volumes; do
  if raw_api GET "/${ep}"; then
    POSITIVE=$((POSITIVE+1)); pass "GET /${ep}"
  else
    info "GET /${ep} blocked (${LAST_CODE})"
  fi
done

printf '\nREAD paths that must be BLOCKED\n'
# These are the ones that would leak other people's data, or hand out creds.
for ep in secrets swarm configs plugins nodes services tasks; do
  if raw_api GET "/${ep}"; then fail "GET /${ep} — REACHED (${LAST_CODE})"; else pass "GET /${ep} blocked (${LAST_CODE})"; fi
done

printf '\nWRITE paths that must be BLOCKED\n'
# Each of these is a way to escalate past the proxy without needing the socket.
if raw_api POST /build; then fail "POST /build — REACHED (${LAST_CODE})"; else pass "POST /build blocked (${LAST_CODE})"; fi
if raw_api POST /exec; then fail "POST /exec — REACHED (${LAST_CODE})"; else pass "POST /exec blocked (${LAST_CODE})"; fi
# Whether the proxy gates on METHOD at all. If CONTAINERS is enabled and the
# section ACL does not check the verb, a hostile caller can DELETE a neighbour —
# the janitor is then not the only thing that can remove a container.
# The target is a name that does not exist, so nothing can actually be deleted:
# a 404 proves the request was FORWARDED and answered by the daemon, which is
# the finding. Only 403 (proxy said no) or 000 (nothing answered) is a block.
if raw_api DELETE "/containers/ww-probe-nonexistent-$$" || [ "$LAST_CODE" = 404 ]; then
  fail "DELETE /containers/... forwarded (${LAST_CODE}) — the proxy does not gate on method"
else
  pass "DELETE /containers/... blocked (${LAST_CODE})"
fi

printf '\nSocket reachable from a browser-ish path?\n'
# Derived from DOCKER_HOST rather than hardcoding the proxy's name: a probe that
# can only be run in one exact deployment is a probe that will not be run.
_probe_host="${DOCKER_HOST#tcp://}"; _probe_host="${_probe_host%%/*}"
if curl -s -m 3 "http://${_probe_host}:2375/_ping" >/dev/null 2>&1; then
  info "proxy answers plain HTTP at ${_probe_host}:2375 (expected on the control network)"
else
  info "proxy does not answer plain HTTP at ${_probe_host}:2375 (unexpected — check it started)"
fi

if [ "$WITH_CREATE" -eq 1 ]; then
  printf '\nTHE METERED TEST — what a hostile create can actually ask for\n'
  printf '  Creating ONE container, immediately removing it.\n\n'

  # 1. A plain, bounded create. This MUST work; if it does not, the proxy's
  #    POST grant is too tight and nothing will run.
  if docker create --name ww-probe-plain --label ww.created-by=probe \
       --label ww.ttl=60 --init --network none alpine:3 true >/dev/null 2>&1; then
    docker rm ww-probe-plain >/dev/null 2>&1
    pass "plain create accepted (expected)"
  else
    info "plain create REJECTED — POST grant too tight; loosen it, nothing runs otherwise"
  fi

  # 2. The question the design rests on. docker-socket-proxy filters by
  #    endpoint, not by request body, so I expect this to be ACCEPTED. If it is,
  #    it is not a proxy bug — it is the reason the broker/VM wall exists, and
  #    ww-run refuses it on the client side instead.
  if docker create --name ww-probe-priv --label ww.created-by=probe \
       --privileged --pid=host alpine:3 true >/dev/null 2>&1; then
    docker rm ww-probe-priv >/dev/null 2>&1
    fail "PRIVILEGED CREATE ACCEPTED — the proxy is not a boundary (as suspected); ww-run's refusal is the only client-side stop"
  else
    pass "privileged create blocked (better than expected — note it)"
  fi

  # 3. Host-root-by-bind-mount, the other escalations shape.
  if docker create --name ww-probe-mount --label ww.created-by=probe \
       -v /:/host alpine:3 true >/dev/null 2>&1; then
    docker rm ww-probe-mount >/dev/null 2>&1
    fail "HOST ROOT BIND MOUNT ACCEPTED (-v /:/host)"
  else
    pass "host root bind mount blocked"
  fi
fi

# A probe that saw nothing succeed cannot be trusted about what it saw fail.
# This gate is the real fix: without it, an unreachable or misconfigured
# endpoint reads exactly like a perfectly locked-down one.
if [ "$POSITIVE" -eq 0 ]; then
  printf '\n\033[31mNo read path succeeded.\033[0m This probe cannot tell "blocked" from\n'
  printf '"could not ask", so every result above is unknown rather than a pass.\n'
  printf 'Check DOCKER_HOST, that the proxy is up, and that curl itself works.\n\n'
  exit 2
fi

printf '\n'
if [ "$PROBLEMS" -eq 0 ]; then
  printf '\033[32mNo unblocked escalations found by this probe.\033[0m\n'
  printf 'That is evidence, not proof: it tests endpoints and a few payloads,\n'
  printf 'not the whole API surface.\n\n'
else
  printf '\033[31m%d issue(s) found.\033[0m See above. The privileged-create result in\n' "$PROBLEMS"
  printf 'particular decides whether this deployment is "guardrails" or "a wall".\n\n'
fi

exit "$PROBLEMS"
