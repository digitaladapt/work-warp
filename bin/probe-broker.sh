#!/usr/bin/env bash
# probe-broker.sh — measure the broker that is now running, rather than trusting
# the prose in deploy/README.md.
#
# It is the client half of the same discipline probe-guardrails.sh applies to the
# proxy: run it before believing anything the deployment docs claim, and re-run it
# after any stack change. It is READ-ONLY with respect to the broker: it creates
# one throwaway container per network it tests from, sends no command, and never
# prints the token.
#
# Why it exists (2026-10-14): the broker is up and `/v1/pkg` answers, and yet two
# of this deployment's load-bearing claims could not be checked from inside the
# terminal container at all —
#
#   1. `lyra-broker` does NOT resolve from inside `lyra-terminal`, whose own name
#      does. Either the broker is on a different `lyra_api` bridge than the
#      terminal (a second compose project's `api` is `<project>_api`, not this
#      one), or the name differs. It cannot be settled without the daemon, and
#      this script is what settles it.
#   2. The terminal cannot see the host's published ports, which is why nothing
#      on the LAN answers :8300 either.
#
# Usage:  ./probe-broker.sh                     (reads WW_TOKEN from the environment)
#         WW_TOKEN=... ./probe-broker.sh
#         BROKER=my-broker-terminal ./probe-broker.sh   (try another service name)
#
# Exit code is the verdict: 0 every check answered as designed, 1 a check failed,
# 2 at least one check could not be made — results are unknown, not clean.
#
# Needs `docker` (it runs on the host) and `curl` inside the probe image.

set -uo pipefail

BROKER_CONTAINER="${BROKER_CONTAINER:-lyra-broker}"
TERMINAL_CONTAINER="${TERMINAL_CONTAINER:-lyra-terminal}"
# The name the terminal is *expected* to use. If the broker answers to another
# one, that is the finding, not a reason to edit this line.
BROKER_HOST="${BROKER:-lyra-broker}"
# The proxy must stay invisible from the terminal — that removal is the design.
PROXY_HOST="lyra-dockerproxy"
PROBE_IMAGE="${PROBE_IMAGE:-curlimages/curl:latest}"

FAILED=0
UNKNOWN=0

ok()      { printf '  \033[32mok\033[0m      %s\n' "$1"; }
bad()     { printf '  \033[31mFAILED\033[0m  %s\n' "$1"; FAILED=$((FAILED+1)); }
unknown() { printf '  \033[33munknown\033[0m %s\n' "$1"; UNKNOWN=$((UNKNOWN+1)); }
info()    { printf '  ----    %s\n' "$1"; }

if ! command -v docker >/dev/null 2>&1; then
  printf '\n\033[31mdocker is not on PATH.\033[0m This probe runs on the host; it needs the\n'
  printf 'daemon to inspect containers and to start its throwaway probes.\n\n'
  exit 2
fi

printf '\nBroker container: %s\n\n' "$BROKER_CONTAINER"

# Reachability first, and separately from "does the container exist": a client
# with no daemon (the agent's terminal container, for instance — it has the CLI
# and deliberately no socket) fails `docker inspect` for a reason that has
# nothing to do with the broker. Without this gate that reads as "there is no
# such container", which is the wrong conclusion to hand someone.
if ! docker info >/dev/null 2>&1; then
  printf '\n\033[31mNo Docker daemon reachable.\033[0m This probe must run where the daemon is\n'
  printf '(a host shell), not from inside a container that has the client and no socket.\n'
  printf 'Nothing was checked.\n\n'
  exit 2
fi

# ── Is it running, and what is it? ─────────────────────────────────────────
if ! docker inspect "$BROKER_CONTAINER" >/dev/null 2>&1; then
  printf '  \033[31mThere is no container named %s.\033[0m Nothing to probe.\n' "$BROKER_CONTAINER"
  printf '  Check the running name:  docker ps --format "{{.Names}}" | sort\n\n'
  exit 2
fi

RUNNING="$(docker inspect -f '{{.State.Running}}' "$BROKER_CONTAINER" 2>/dev/null)"
if [ "$RUNNING" = "true" ]; then
  ok "the container is running"
else
  bad "the container exists but is not running (state: ${RUNNING:-unknown})"
fi

BROKER_ID="$(docker inspect -f '{{.Image}}' "$BROKER_CONTAINER" 2>/dev/null)"
BROKER_IMAGE="$(docker inspect -f '{{.Config.Image}}' "$BROKER_CONTAINER" 2>/dev/null)"
info "running image: ${BROKER_IMAGE:-?} (${BROKER_ID:0:19}…)"

# The janitor's pcntl dependency (2026-10-14): an image built before the fix
# carries the broker but not the extension, and the only visible symptom is that
# the host stops being swept. Say which image is live so the two can be told
# apart, and check the extension from inside the container itself.
if docker exec "$BROKER_CONTAINER" php -m 2>/dev/null | grep -qx pcntl; then
  ok "php has pcntl (the janitor loop can subscribe to signals)"
elif docker exec "$BROKER_CONTAINER" php -m >/dev/null 2>&1; then
  bad "php inside the container has no pcntl — ww:janitor dies at start and the entrypoint retries it every 5s, so the TTL sweep never runs. Rebuild/repull the image (Dockerfile installs pcntl intl)."
else
  unknown "could not read the container's php modules (docker exec failed) — the pcntl check is unanswered"
fi

# ── The network question: does the terminal share a network with the broker? ──
BROKER_NETS="$(docker inspect -f '{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}' "$BROKER_CONTAINER" 2>/dev/null | tr -s ' ' | sed 's/ $//')"
info "broker networks: ${BROKER_NETS:-<none>}"

if docker inspect "$TERMINAL_CONTAINER" >/dev/null 2>&1; then
  TERMINAL_NETS="$(docker inspect -f '{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}' "$TERMINAL_CONTAINER" 2>/dev/null | tr -s ' ' | sed 's/ $//')"
  info "terminal networks: ${TERMINAL_NETS:-<none>}"

  SHARED=0
  for net in $BROKER_NETS; do
    for own in $TERMINAL_NETS; do
      [ "$net" = "$own" ] && SHARED=1
    done
  done

  if [ "$SHARED" = "1" ]; then
    ok "the terminal and the broker share at least one network"
  else
    bad "the terminal and the broker share NO network — the terminal cannot reach the broker by any name. A compose project that is not the terminal's creates its own \`api\` bridge (<project>_api), which looks exactly like this."
  fi
else
  unknown "no container named $TERMINAL_CONTAINER; skipped the shared-network check"
fi

# ── Does the terminal actually reach it, by the name the docs use? ───────────
# Run from inside the terminal itself: this is the check deploy/README.md's step 6
# performs, and the one that decides whether a client config can name `lyra-broker`.
if docker inspect "$TERMINAL_CONTAINER" >/dev/null 2>&1; then
  printf '\n  from inside %s:\n' "$TERMINAL_CONTAINER"

  if docker exec "$TERMINAL_CONTAINER" getent hosts "$BROKER_HOST" >/dev/null 2>&1; then
    ok "\`$BROKER_HOST\` resolves"
  else
    bad "\`$BROKER_HOST\` does NOT resolve — the name in deploy/README.md's client steps, and any client config using it, will fail with a name-resolution error rather than a 401. Find the name that does answer, then correct the document (host is the fact)."
  fi

  # The other half of the same pair, and it must fail: if the proxy resolves from
  # the terminal, the 2026-10-07 removal has been undone, whatever compose says.
  if docker exec "$TERMINAL_CONTAINER" getent hosts "$PROXY_HOST" >/dev/null 2>&1; then
    bad "\`$PROXY_HOST\` resolves from the terminal — the socket proxy is reachable again and the broker is no longer the only path to Docker."
  else
    ok "\`$PROXY_HOST\` does not resolve (expected — the broker is the only path)"
  fi

  if docker exec "$TERMINAL_CONTAINER" sh -c "curl -fsS -m 5 http://$BROKER_HOST/health" >/dev/null 2>&1; then
    ok "/health answers from the terminal"
  else
    bad "/health does not answer from the terminal at http://$BROKER_HOST/health"
  fi
fi

# ── The gate, from a throwaway container on each of the broker's networks ────
# A 401/503 without the token and a 200 with it is the whole point: it proves the
# gate covers /v1 and that the manifest is readable by a real caller.
if [ -z "${WW_TOKEN:-}" ]; then
  unknown "WW_TOKEN is not set here, so the authenticated calls are unanswered. Set it (the value in broker.env, beside the live compose file) and re-run. Note that this is the token the *caller* presents, not the broker's copy of it."
else
  for net in $BROKER_NETS; do
    printf '\n  from a throwaway container on %s:\n' "$net"

    if docker run --rm --network "$net" "$PROBE_IMAGE" \
        sh -c "curl -s -o /dev/null -w '%{http_code}' -m 5 http://$BROKER_HOST/health" 2>/dev/null | grep -q '^200$'; then
      ok "/health answers 200"
    else
      bad "/health did not answer 200 from $net"
    fi

    NO_TOKEN="$(docker run --rm --network "$net" "$PROBE_IMAGE" \
        sh -c "curl -s -o /dev/null -w '%{http_code}' -m 5 http://$BROKER_HOST/v1/pkg" 2>/dev/null || echo 000)"

    case "$NO_TOKEN" in
      401|403|503) ok "/v1/pkg refuses a caller with no token ($NO_TOKEN)" ;;
      200)         bad "/v1/pkg answered 200 with NO token — the bearer gate is not covering the MCP path, which is unauthenticated command execution." ;;
      *)           bad "/v1/pkg returned $NO_TOKEN with no token (000 = nothing answered)" ;;
    esac

    WITH_TOKEN="$(docker run --rm --network "$net" -e T="$WW_TOKEN" "$PROBE_IMAGE" \
        sh -c "curl -s -o /dev/null -w '%{http_code}' -m 5 -H \"Authorization: Bearer \$T\" http://$BROKER_HOST/v1/pkg" 2>/dev/null || echo 000)"

    if [ "$WITH_TOKEN" = "200" ]; then
      ok "/v1/pkg answers 200 with the token"
    else
      bad "/v1/pkg returned $WITH_TOKEN with the token (401 means the client half does not match the broker's)"
    fi
  done
fi

# ── The sweep, which is the thing pcntl silently takes away ──────────────────
# The compose block logs through the syslog driver, so `docker logs` is empty by
# design — the lines land in the host's syslog under the `lyra-broker` tag. Try
# both, and treat "could not read" as unknown rather than as a failure.
printf '\n  the janitor sweep:\n'

SWEEP="$(docker logs --since 5m "$BROKER_CONTAINER" 2>&1 | grep -i -e 'janitor' -e 'sweep' | tail -3)"
if [ -z "$SWEEP" ]; then
  if command -v journalctl >/dev/null 2>&1; then
    SWEEP="$(journalctl -t lyra-broker --since '-5 min' --no-pager 2>/dev/null | grep -i -e 'janitor' -e 'sweep' | tail -3)"
  fi
fi
if [ -z "$SWEEP" ] && [ -r /var/log/syslog ]; then
  SWEEP="$(grep 'lyra-broker' /var/log/syslog 2>/dev/null | grep -i -e 'janitor' -e 'sweep' | tail -3)"
fi

if [ -n "$SWEEP" ]; then
  printf '%s\n' "$SWEEP" | sed 's/^/          /'
  if printf '%s' "$SWEEP" | grep -qi 'Signals are not supported'; then
    bad "the janitor is reporting the pcntl failure — the sweep is not running."
  else
    ok "the janitor is logging sweeps (see the lines above)"
  fi
else
  unknown "no janitor lines found in the last 5 minutes via docker logs, journalctl -t lyra-broker, or /var/log/syslog. The compose service uses the syslog driver, so look on the host before concluding it is broken — and note that a missing sweep is exactly what a missing pcntl looks like."
fi

# ── Verdict ─────────────────────────────────────────────────────────────────
printf '\n'
if [ "$FAILED" -eq 0 ] && [ "$UNKNOWN" -eq 0 ]; then
  printf '\033[32mEvery check answered as designed.\033[0m\n\n'
  exit 0
fi

if [ "$FAILED" -gt 0 ]; then
  printf '\033[31m%d check(s) failed.\033[0m\n' "$FAILED"
fi

if [ "$UNKNOWN" -gt 0 ]; then
  printf '\033[33m%d check(s) could not be made.\033[0m Unknown is not clean: answer\n' "$UNKNOWN"
  printf 'them before reading a pass into the rest.\n'
  exit 2
fi

printf '\n'
exit 1
