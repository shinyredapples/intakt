# Pinned by digest to FrankenPHP 1.13.4 on PHP 8.5.12, the digest pin #705 asks for; 1.13.4 is the first release with every worker-mode state-leak fix.
FROM dunglas/frankenphp:1.13.4-php8.5-trixie@sha256:6f86f003f1478bafa7befe6ec26db5575e703882171f785189de1e222df0e38e AS base
RUN php -r 'exit(version_compare(PHP_VERSION, "8.5.12", ">=") ? 0 : 1);'

COPY composer.json /usr/local/share/intakt/

# The extensions are composer.json's ext-* entries (pcntl among them lets a consumer finish its message on SIGTERM);
# opcache is no profile entry, it is only for serving. No check.ini: the image keeps production settings.
# The list is assigned before use because set -e ignores a failing command substitution inside a command's arguments.
RUN set -eu; \
    missing='$c = json_decode(file_get_contents("/usr/local/share/intakt/composer.json"), true, 512, JSON_THROW_ON_ERROR); if (!is_array($c["require"] ?? null)) { throw new UnexpectedValueException("composer.json has no require object"); } foreach ($c["require"] as $p => $v) { $e = substr($p, 4); if (str_starts_with($p, "ext-") && !extension_loaded($e)) { echo "$e "; } }'; \
    exts="$(php -r "$missing")"; \
    install-php-extensions $exts opcache; \
    left="$(php -r "$missing")"; \
    test -z "$left"; \
    php -r 'if (!extension_loaded("Zend OPcache")) { fwrite(STDERR, "missing PHP extension opcache\n"); exit(1); }'

WORKDIR /app

FROM composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 AS composer

FROM base AS vendor

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction

COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && APP_ENV=prod php bin/console importmap:install --no-debug \
    && APP_ENV=prod php bin/console asset-map:compile --no-debug \
    && APP_ENV=prod php bin/console cache:warmup --no-debug

FROM base

# The base image has no git, which the local git forge reads a change with and the demo builds its sample repository with; Debian's is 2.45 or later (--no-lazy-fetch); ssh-keygen signs and checks the commits of a pushed branch.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git openssh-client \
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
    XDG_DATA_HOME=/app/var/data/share \
    INTAKT_DEMO_RECORDING=/app/fixtures/demo/sample-change-review.json

COPY --from=vendor /app /app
COPY docker/entrypoint.sh /usr/local/bin/intakt-entrypoint
COPY docker/healthcheck.sh /usr/local/bin/intakt-healthcheck

# One fixed user id, so a volume can be chowned to it from the host.
RUN useradd --uid 10001 --no-create-home --shell /usr/sbin/nologin intakt \
    && mkdir -p /app/var/data \
    && chown -R intakt:intakt /app/var \
    && chmod 755 /usr/local/bin/intakt-entrypoint /usr/local/bin/intakt-healthcheck

USER intakt
# No VOLUME: this is also Intakt's own job image, and the job runner refuses an image that declares one. Mount /app/var/data when running it.
EXPOSE 7781

# The admin API answers on localhost only, so the check runs inside the container: intakt:server:health fails on an unhealthy hub, no ready worker thread or an admin endpoint that does not answer, so Docker marks the container unhealthy. The script also asks the dashboard and, in the demo, waits for the seed.
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD ["intakt-healthcheck"]

ENTRYPOINT ["intakt-entrypoint"]
