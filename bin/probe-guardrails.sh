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

set -uo pipefail

WITH_CREATE=0
[ "${1:-}" = "--with-create" ] && WITH_CREATE=1

pass() { printf '  \033[32mok\033[0m      %s\n' "$1"; }
fail() { printf '  \033[31mREACHED\033[0m %s\n' "$1"; PROBLEMS=$((PROBLEMS+1)); }
info() { printf '  ----    %s\n' "$1"; }
PROBLEMS=0

printf '\nDocker endpoint: %s\n\n' "${DOCKER_HOST:-<unset>}"

if ! docker info >/dev/null 2>&1; then
  printf '  \033[31mNo daemon reachable.\033[0m Nothing to probe.\n\n'
  exit 1
fi

printf 'READ paths\n'
for ep in version info containers/json images/json networks volumes; do
  if docker api "/${ep}" >/dev/null 2>&1; then pass "GET /${ep}"; else info "GET /${ep} blocked"; fi
done

printf '\nREAD paths that must be BLOCKED\n'
# These are the ones that would leak other people's data, or hand out creds.
for ep in secrets swarm configs plugins nodes services tasks; do
  if docker api "/${ep}" >/dev/null 2>&1; then fail "GET /${ep}"; else pass "GET /${ep} blocked"; fi
done

printf '\nWRITE paths that must be BLOCKED\n'
# Each of these is a way to escalate past the proxy without needing the socket.
if docker api -X POST /build >/dev/null 2>&1; then fail "POST /build"; else pass "POST /build blocked"; fi
if docker api -X POST /exec >/dev/null 2>&1; then fail "POST /exec (on some container)"; else pass "POST /exec blocked"; fi

printf '\nSocket reachable from a browser-ish path?\n'
if curl -s -m 3 http://lyra-dockerproxy:2375/version >/dev/null 2>&1; then
  info "proxy answers plain HTTP on port 2375 (expected on the control network)"
else
  info "proxy does not answer plain HTTP (unexpected — check it started)"
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
