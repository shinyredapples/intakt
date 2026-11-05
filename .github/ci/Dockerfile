# The image every CI job runs Intakt's code in (.github/workflows/ci.yml), built by .github/ci/build-image.sh. The jobs
# run it as uid 1000, never as root. PHP 8.5.12 or later (PHP2Shell, CVE-2026-109618 and CVE-2026-109619).
FROM dunglas/frankenphp:1.13.4-php8.5.12-trixie@sha256:6f86f003f1478bafa7befe6ec26db5575e703882171f785189de1e222df0e38e

ARG COMPOSER_VERSION=2.11.0
ARG COMPOSER_SHA256=9674f12a2a181100d889ebfd0b4510805b3e94cea57869b14b383753d602599b
ARG GITLEAKS_VERSION=8.30.1
ARG GITLEAKS_SHA256=551f6fc83ea457d62a0d98237cbad105af8d557003051f41f3e7ca7b3f2470eb

# The base image's XDG_*_HOME point Caddy at root's /config and /data; the jobs run as uid 1000.
RUN set -eu; \
    apt-get update; \
    apt-get install --yes --no-install-recommends git iproute2 jq libicu-dev libpq-dev libzip-dev python3 unzip; \
    rm -rf /var/lib/apt/lists/*; \
    docker-php-ext-install -j"$(nproc)" intl pcntl pdo_mysql pdo_pgsql zip; \
    echo 'memory_limit=-1' > "$PHP_INI_DIR/conf.d/ci.ini"; \
    curl -fsSL -o /usr/local/bin/composer "https://getcomposer.org/download/$COMPOSER_VERSION/composer.phar"; \
    echo "$COMPOSER_SHA256  /usr/local/bin/composer" | sha256sum --check --strict; \
    chmod 755 /usr/local/bin/composer; \
    curl -fsSL -o /tmp/gitleaks.tar.gz "https://github.com/gitleaks/gitleaks/releases/download/v$GITLEAKS_VERSION/gitleaks_${GITLEAKS_VERSION}_linux_x64.tar.gz"; \
    echo "$GITLEAKS_SHA256  /tmp/gitleaks.tar.gz" | sha256sum --check --strict; \
    tar -xzf /tmp/gitleaks.tar.gz -C /usr/local/bin gitleaks; \
    rm /tmp/gitleaks.tar.gz; \
    frankenphp version; \
    php -v; \
    php -r 'foreach (["intl", "pcntl", "zip", "pdo_mysql", "pdo_pgsql"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "missing PHP extension $e\n"); exit(1); } }'; \
    useradd --uid 1000 --user-group --create-home ci; \
    chown -R ci:ci /config/caddy /data/caddy

USER 1000:1000
WORKDIR /home/ci
