#!/bin/sh
# Runs inside the CI image as uid 1000 with the checkout mounted read-only at /workspace: installs the locked
# dependencies on a copy in the home directory, then runs its arguments there, so a job never writes to the runner's
# workspace and never runs as root.
set -eu
php -r 'echo PHP_VERSION, PHP_EOL; exit(version_compare(PHP_VERSION, "8.5.12", ">=") ? 0 : 1);'
cp -R /workspace "$HOME/intakt"
cd "$HOME/intakt"
# Without GH_TOKEN, so install-time Composer plugins and scripts never see an issue-write token.
env -u GH_TOKEN composer install --no-interaction --no-progress --prefer-dist
exec "$@"
