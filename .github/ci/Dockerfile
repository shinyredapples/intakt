# The image every CI job runs Intakt's code in (.github/workflows/ci.yml), built by .github/ci/build-image.sh. The jobs
# run it as uid 1000, never as root. PHP 8.5.12 or later (PHP2Shell, CVE-2026-109618 and CVE-2026-109619).
FROM php:8.5-cli@sha256:acaa95199b53a1d7c346dddc7bcfc367ef3bd21ab832bd178eac7f043ed5396c

ARG COMPOSER_VERSION=2.11.0
ARG COMPOSER_SHA256=9674f12a2a181100d889ebfd0b4510805b3e94cea57869b14b383753d602599b
ARG GITLEAKS_VERSION=8.30.1
ARG GITLEAKS_SHA256=551f6fc83ea457d62a0d98237cbad105af8d557003051f41f3e7ca7b3f2470eb
ARG FRANKENPHP_VERSION=1.13.4
ARG FRANKENPHP_SHA256=3bbd2f11929550e3b35ddb407c7981ef14bc5337e705c910068f85a6ccbf91f3

RUN set -eu; \
    apt-get update; \
    apt-get install --yes --no-install-recommends git iproute2 jq libpq-dev python3 unzip; \
    rm -rf /var/lib/apt/lists/*; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_pgsql; \
    echo 'memory_limit=-1' > "$PHP_INI_DIR/conf.d/ci.ini"; \
    curl -fsSL -o /usr/local/bin/composer "https://getcomposer.org/download/$COMPOSER_VERSION/composer.phar"; \
    echo "$COMPOSER_SHA256  /usr/local/bin/composer" | sha256sum --check --strict; \
    chmod 755 /usr/local/bin/composer; \
    curl -fsSL -o /tmp/gitleaks.tar.gz "https://github.com/gitleaks/gitleaks/releases/download/v$GITLEAKS_VERSION/gitleaks_${GITLEAKS_VERSION}_linux_x64.tar.gz"; \
    echo "$GITLEAKS_SHA256  /tmp/gitleaks.tar.gz" | sha256sum --check --strict; \
    tar -xzf /tmp/gitleaks.tar.gz -C /usr/local/bin gitleaks; \
    rm /tmp/gitleaks.tar.gz; \
    curl -fsSL -o /usr/local/bin/frankenphp "https://github.com/php/frankenphp/releases/download/v$FRANKENPHP_VERSION/frankenphp-linux-x86_64"; \
    echo "$FRANKENPHP_SHA256  /usr/local/bin/frankenphp" | sha256sum --check --strict; \
    chmod 755 /usr/local/bin/frankenphp; \
    useradd --uid 1000 --user-group --create-home ci

USER 1000:1000
WORKDIR /home/ci
