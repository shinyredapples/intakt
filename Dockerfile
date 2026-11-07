# Pinned by digest to FrankenPHP 1.13.4 on PHP 8.5.12, the digest pin #705 asks for; 1.13.4 is the first release with every worker-mode state-leak fix.
FROM dunglas/frankenphp:1.13.4-php8.5.12-trixie@sha256:6f86f003f1478bafa7befe6ec26db5575e703882171f785189de1e222df0e38e AS base

WORKDIR /app

# The extensions are the ext-* entries of composer.json (pcntl lets a consumer finish its message on SIGTERM).
COPY composer.json docker/profile-extensions.php ./
RUN install-php-extensions $(php profile-extensions.php) \
    && missing="$(php profile-extensions.php)" && [ -z "$missing" ] \
    && rm profile-extensions.php

FROM base AS vendor

COPY --from=composer/composer:2-bin /composer /usr/bin/composer
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction

COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && APP_ENV=prod php bin/console importmap:install --no-debug \
    && APP_ENV=prod php bin/console asset-map:compile --no-debug \
    && APP_ENV=prod php bin/console cache:warmup --no-debug

FROM base

# The base image has no git, which the local git forge reads a change with and the demo builds its sample repository with; Debian's is 2.45 or later (--no-lazy-fetch).
RUN apt-get update \
    && apt-get install -y --no-install-recommends git \
    && rm -rf /var/lib/apt/lists/*

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    SERVER_PORT=7781 \
    DASHBOARD_PORT=7781 \
    REVIEW_MCP_BASE_URL=http://localhost:7781 \
    MERCURE_JWT_ISSUER=http://localhost:7781 \
    FRANKENPHP_LOOP_MAX=500 \
    WORKER_MEMORY_CEILING_MB=256 \
    MESSENGER_CONSUMERS=3 \
    DATABASE_URL="sqlite:///%kernel.project_dir%/var/data/intakt.db" \
    MERCURE_BOLT_PATH=var/data/mercure.db \
    XDG_CONFIG_HOME=/app/var/data/config \
    XDG_DATA_HOME=/app/var/data/share

COPY --from=vendor /app /app
COPY docker/entrypoint.sh /usr/local/bin/intakt-entrypoint

# One fixed user id, so a volume can be chowned to it from the host.
RUN useradd --uid 10001 --no-create-home --shell /usr/sbin/nologin intakt \
    && mkdir -p /app/var/data \
    && chown -R intakt:intakt /app/var \
    && chmod 755 /usr/local/bin/intakt-entrypoint

USER intakt
VOLUME /app/var/data
EXPOSE 7781

# The admin API answers on localhost only; its metrics endpoint stops answering when the server is stuck, so Docker marks the container unhealthy.
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl --fail --silent --show-error --output /dev/null "http://${CADDY_ADMIN:-localhost:2019}/metrics" || exit 1

ENTRYPOINT ["intakt-entrypoint"]
