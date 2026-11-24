#!/bin/bash
set -euo pipefail

# Green only when the server is healthy (the hub healthy, a worker thread ready, every admin API endpoint answering) and the dashboard answers; in the demo also only once the entrypoint has seeded the sample, so `docker compose up --wait` returns when the page can be opened.
# The admin API moves with CADDY_ADMIN, so the reader follows it unless FRANKENPHP_ADMIN_URL is set itself.
if [[ -n ${CADDY_ADMIN:-} && -z ${FRANKENPHP_ADMIN_URL:-} ]]; then
    export FRANKENPHP_ADMIN_URL="http://${CADDY_ADMIN}"
fi
php bin/console intakt:server:health --no-ansi
curl --fail --silent --show-error --max-time 4 --output /dev/null -H "Host: localhost:${DASHBOARD_PORT:-7781}" "http://localhost:${SERVER_PORT:-7781}/"

if [[ -n ${INTAKT_SAMPLE_REPOSITORY:-} && ! -e var/sample-ready ]]; then
    echo "intakt: the sample is not seeded yet" >&2
    exit 1
fi
