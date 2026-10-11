#!/bin/sh
# work-warp broker container entrypoint.
#
# Secrets and configuration come from the environment, never from the image
# (Guiding Light §8.12). In prod, APP_SECRET and WW_TOKEN must be provided at
# runtime — this script deliberately does not invent either, because a broker
# that starts with a guessed credential is worse than one that refuses to start.

set -e

if [ "$APP_ENV" = "prod" ]; then
    # Warm the compiled container before the first request, so the first caller
    # is not the first compile.
    php bin/console cache:warmup --env=prod
fi

# The sweep, as a child of this process — "no cron and no second service"
# (DOCKER-ACCESS.md §6a). It starts whenever a Docker host is configured (the
# first sweep will say plainly if that host is not answering); without one
# configured, every interval would be a logged warning about a host that does
# not exist. `set +e` inside the subshell and the 5-second pause make it
# restart itself if it ever dies, so a crash cannot leave the host unswept; a
# normal exit happens only on a stop signal, when the container is going away
# anyway. The interval is a knob, not policy: WW_JANITOR_INTERVAL, default 60.
if [ -n "${WW_DOCKER_HOST:-}" ]; then
    (
        set +e
        while true; do
            php bin/console ww:janitor --every "${WW_JANITOR_INTERVAL:-60}"
            sleep 5
        done
    ) &
fi

exec "$@"
