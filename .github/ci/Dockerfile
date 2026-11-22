# The image every CI job runs Intakt's code in (.github/workflows/ci.yml), built by .github/ci/build-image.sh. The jobs
# run it as uid 1000, never as root. PHP 8.5.12 or later (PHP2Shell, CVE-2026-109618 and CVE-2026-109619).
# The tools' own images are stages, not COPY --from=<image> references, because Dependabot's docker updater reads only
# FROM lines; pinned by tag and digest, they are kept current with the base. gitleaks publishes its image on GHCR; its
# binary is static, so it runs on the Debian base. zizmor's image holds its glibc build, which runs on Debian's glibc.
FROM composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 AS composer
FROM ghcr.io/gitleaks/gitleaks:v8.30.1@sha256:c00b6bd0aeb3071cbcb79009cb16a60dd9e0a7c60e2be9ab65d25e6bc8abbb7f AS gitleaks
FROM ghcr.io/zizmorcore/zizmor:1.32.0@sha256:3014cc8f68f663935f69b3c3e0e41f5ce102b268d97db13db0f62208747f860d AS zizmor

FROM dunglas/frankenphp:1.13.4-php8.5-trixie@sha256:6f86f003f1478bafa7befe6ec26db5575e703882171f785189de1e222df0e38e
RUN php -r 'exit(version_compare(PHP_VERSION, "8.5.12", ">=") ? 0 : 1);'

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=gitleaks /usr/bin/gitleaks /usr/local/bin/gitleaks
COPY --from=zizmor /usr/bin/zizmor /usr/local/bin/zizmor

# Like the old binary: no file capability (secure-exec drops TMPDIR); uid 1000 owns Caddy's XDG_*_HOME dirs.
# A fixed list before the copy, so a composer.json edit reuses this layer; pdo_mysql and pdo_pgsql serve persistence.
# openssh-client: the branch push signs Intakt's commits with ssh-keygen and verifies them before it pushes, the
# watchdog verifies main's SSH-signed commits with it, and their tests sign with throwaway keys.
RUN set -eu; \
    apt-get update; \
    apt-get install --yes --no-install-recommends git iproute2 jq libicu-dev libpq-dev libzip-dev openssh-client python3 unzip; \
    rm -rf /var/lib/apt/lists/*; \
    docker-php-ext-install -j"$(nproc)" intl pcntl zip pdo_mysql pdo_pgsql; \
    php --ini; \
    composer --version; \
    gitleaks version; \
    zizmor --version; \
    keys="$(mktemp -d)"; ssh-keygen -q -t ed25519 -N '' -C '' -f "$keys/key"; ssh-keygen -l -f "$keys/key.pub"; rm -r "$keys"; \
    setcap -r /usr/local/bin/frankenphp; \
    frankenphp version; \
    php -v; \
    php -r 'foreach (["pdo_mysql", "pdo_pgsql"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "missing PHP extension $e\n"); exit(1); } }'; \
    useradd --uid 1000 --user-group --create-home ci; \
    chown -R ci:ci /config/caddy /data/caddy

COPY composer.json /usr/local/share/intakt/
COPY config/gates/php/check.ini /usr/local/etc/php/conf.d/zz-check.ini
RUN set -eu; \
    missing='$c = json_decode(file_get_contents("/usr/local/share/intakt/composer.json"), true, 512, JSON_THROW_ON_ERROR); if (!is_array($c["require"] ?? null)) { throw new UnexpectedValueException("composer.json has no require object"); } foreach ($c["require"] as $p => $v) { $e = substr($p, 4); if (str_starts_with($p, "ext-") && !extension_loaded($e)) { echo "$e "; } }'; \
    left="$(php -r "$missing")"; \
    for e in $left; do echo "missing PHP extension $e" >&2; done; \
    test -z "$left"

USER 1000:1000
WORKDIR /home/ci
