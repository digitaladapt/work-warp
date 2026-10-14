# syntax=docker/dockerfile:1.7
#
# work-warp — the broker that holds the Docker credential.
#
# A multi-stage FrankenPHP image, the same shape as every other service in the
# family. The broker keeps no state of its own: sessions and workspaces live in
# named volumes and the ledger, never in this container (§4 of PLAN.md). That is
# what makes it safe to kill, rebuild and restart at will.
#
# The broker is the only thing in the system that talks to the Docker socket
# proxy, and it does so through a ~10-endpoint API in which dangerous operations
# are unexpressible rather than refused (DOCKER-ACCESS.md §2, §5).

# ── Stage: deps — composer dependencies (layer-cached) ─────────────────────
FROM dunglas/frankenphp:1-php8.5-trixie AS deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# git (composer resolves some packages over VCS) and unzip (dist extraction).
# Neither reaches the runtime image.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

# Manifests first, so dependency layers only rebuild when they change.
# .dockerignore excludes host vendor/, so the image builds its own — a locally
# built image is as valid as a CI-built one.
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist \
    --optimize-autoloader --no-scripts

# ── Stage: build — full application + prod autoloader ─────────────────────
FROM deps AS build

COPY . .

RUN composer dump-autoload --classmap-authoritative --no-dev \
    && rm -rf var/cache/* var/log/*

# Build-time smoke of the autoloader and config compile — and it must be able
# to fail, or it is decoration: a trailing `|| true` used to swallow every
# failure it was written to catch, including this one. The image ships no
# `.env`, so the boot needs the same APP_RUNTIME_OPTIONS the runtime stage
# sets below; without it this RUN dies with `PathException: Unable to read
# "/app/.env"`. The placeholder secrets are build-only; the real ones are
# injected at runtime (§8.12).
RUN APP_ENV=prod APP_SECRET=build-secret APP_RUNTIME_OPTIONS='{"disable_dotenv":true}' WW_DOCKER_HOST= bin/console cache:warmup \
    && rm -rf var/cache/*

# ── Stage: app — the runtime image ────────────────────────────────────────
FROM dunglas/frankenphp:1-php8.5-trixie AS app

# ca-certificates (TLS to the proxy or a registry) and curl (HEALTHCHECK).
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl \
    && install-php-extensions pcntl intl \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /app
COPY --from=build /app /app

# Non-root runtime user (Guiding Light §6.4). uid/gid 1000, the same convention
# as the rest of the family.
RUN groupadd --system --gid 1000 app \
 && useradd  --system --uid 1000 --gid app \
             --home-dir /app --shell /usr/sbin/nologin app \
 && mkdir -p /app/var \
 && chown -R app:app /app/var

# Symfony writes its compiled container into var/ at start-up (entrypoint), and
# nothing else. If a deployment wants the strongest possible shape, mount a
# tmpfs at /app/var and run the container with --read-only: the broker holds no
# state, so nothing here needs to survive the process.

USER app

ENV APP_ENV=prod
ENV SERVER_NAME=:80

# `.dockerignore` excludes every dotenv file: configuration comes from the
# environment, never the image. Left to itself the runtime reads `/app/.env`
# unconditionally and dies on it (PathException) — the entrypoint's warmup
# first, and `set -e` makes that fatal. This is the same line the family's
# images carry; both SAPIs here see the environment (the CLI merges it into
# $_SERVER, and FrankenPHP exposes it there by contract).
ENV APP_RUNTIME_OPTIONS='{"disable_dotenv":true}'

EXPOSE 80

# Liveness only: /health touches nothing, so a restart is never triggered by a
# dependency being briefly down. Readiness (/ready) is the orchestrator's job.
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD curl -f http://localhost/health || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
