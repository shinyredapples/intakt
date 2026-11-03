# The tag must become tag@sha256:<digest> before a release (the sandbox that wrote this file could not reach the registry); 1.13.4 is the first release with every worker-mode state-leak fix.
FROM dunglas/frankenphp:1.13.4-php8.5 AS base

RUN install-php-extensions intl pdo_sqlite opcache zip

WORKDIR /app

FROM base AS vendor

COPY --from=composer/composer:2-bin /composer /usr/bin/composer
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction

COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && APP_ENV=prod php bin/console cache:warmup --no-debug

FROM base

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    SERVER_PORT=7781 \
    DASHBOARD_PORT=7781 \
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

ENTRYPOINT ["intakt-entrypoint"]
