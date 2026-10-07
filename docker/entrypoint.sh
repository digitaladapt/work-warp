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

exec "$@"
