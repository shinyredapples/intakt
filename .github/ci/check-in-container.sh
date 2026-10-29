#!/bin/sh
# The check job's body, run as root inside the pinned PHP image (.github/workflows/ci.yml) with the checkout mounted
# read-only at /workspace. It installs what bin/check needs, then runs bin/check on a copy owned by an unprivileged
# user, because the gate tests need a non-root user and the runner's workspace must stay as the runner left it.
# Its arguments go to bin/check.
set -eu

php -r 'echo PHP_VERSION, PHP_EOL; exit(version_compare(PHP_VERSION, "8.5.12", ">=") ? 0 : 1);'

apt-get update -qq
apt-get install --yes --no-install-recommends -qq git iproute2 python3 unzip > /dev/null
# bin/check starts PHPStan and the tests in their own processes, which need more than the image's 128M default.
echo 'memory_limit=-1' > "$PHP_INI_DIR/conf.d/ci.ini"

download() {
    curl -fsSL -o "$2" "$1"
    echo "$3  $2" | sha256sum --check --strict
}

composer_version=2.11.0
download "https://getcomposer.org/download/$composer_version/composer.phar" /usr/local/bin/composer \
    9674f12a2a181100d889ebfd0b4510805b3e94cea57869b14b383753d602599b
chmod 755 /usr/local/bin/composer

gitleaks_version=8.30.1
download "https://github.com/gitleaks/gitleaks/releases/download/v$gitleaks_version/gitleaks_${gitleaks_version}_linux_x64.tar.gz" /tmp/gitleaks.tar.gz \
    551f6fc83ea457d62a0d98237cbad105af8d557003051f41f3e7ca7b3f2470eb
tar -xzf /tmp/gitleaks.tar.gz -C /usr/local/bin gitleaks

useradd --uid 1000 --create-home ci
cp -a /workspace /home/ci/intakt
chown -R ci:ci /home/ci/intakt
cd /home/ci/intakt
exec runuser -u ci -- sh -c 'composer install --no-interaction --no-progress --prefer-dist && exec bin/check "$@"' sh "$@"
