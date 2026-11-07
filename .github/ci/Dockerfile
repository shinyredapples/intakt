# The image every CI job runs Intakt's code in (.github/workflows/ci.yml), built by .github/ci/build-image.sh. The jobs
# run it as uid 1000, never as root. PHP 8.5.12 or later (PHP2Shell, CVE-2026-109618 and CVE-2026-109619).
# The tools' own images are stages, not COPY --from=<image> references, because Dependabot's docker updater reads only
# FROM lines; pinned by tag and digest, they are kept current with the base. gitleaks publishes its image on GHCR; its
# binary is static, so it runs on the Debian base.
FROM composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 AS composer
FROM ghcr.io/gitleaks/gitleaks:v8.30.1@sha256:c00b6bd0aeb3071cbcb79009cb16a60dd9e0a7c60e2be9ab65d25e6bc8abbb7f AS gitleaks

FROM dunglas/frankenphp:1.13.4-php8.5.12-trixie@sha256:6f86f003f1478bafa7befe6ec26db5575e703882171f785189de1e222df0e38e

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=gitleaks /usr/bin/gitleaks /usr/local/bin/gitleaks
# The PHP profile (build-image.sh sends these files as the context): composer.json's extensions and the check ini.
COPY composer.json docker/profile-extensions.php /usr/local/share/intakt/
COPY config/gates/php/check.ini /usr/local/etc/php/conf.d/zz-check.ini

# Like the old binary: no file capability (secure-exec drops TMPDIR); uid 1000 owns Caddy's XDG_*_HOME dirs.
# pdo_mysql and pdo_pgsql are not in the profile: only the persistence group in CI uses them.
RUN set -eu; \
    apt-get update; \
    apt-get install --yes --no-install-recommends git iproute2 jq libicu-dev libpq-dev libzip-dev python3 unzip; \
    rm -rf /var/lib/apt/lists/*; \
    profile='/usr/local/share/intakt/profile-extensions.php /usr/local/share/intakt/composer.json'; \
    docker-php-ext-install -j"$(nproc)" $(php $profile) pdo_mysql pdo_pgsql; \
    missing="$(php $profile)"; [ -z "$missing" ] || { echo "missing PHP extensions: $missing" >&2; exit 1; }; \
    php --ini; \
    composer --version; \
    gitleaks version; \
    setcap -r /usr/local/bin/frankenphp; \
    frankenphp version; \
    php -v; \
    php -r 'foreach (["pdo_mysql", "pdo_pgsql"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "missing PHP extension $e\n"); exit(1); } }'; \
    useradd --uid 1000 --user-group --create-home ci; \
    chown -R ci:ci /config/caddy /data/caddy

USER 1000:1000
WORKDIR /home/ci
