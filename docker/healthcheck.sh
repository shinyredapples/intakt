#!/bin/bash
set -euo pipefail

# Green only when the server's admin API answers, and the dashboard too; in the demo also only once the entrypoint has seeded the sample, so `docker compose up --wait` returns when the page can be opened.
curl --fail --silent --show-error --max-time 4 --output /dev/null "http://${CADDY_ADMIN:-localhost:2019}/metrics"
curl --fail --silent --show-error --max-time 4 --output /dev/null -H "Host: localhost:${DASHBOARD_PORT:-7781}" "http://localhost:${SERVER_PORT:-7781}/"

if [[ -n ${INTAKT_SAMPLE_REPOSITORY:-} && ! -e var/sample-ready ]]; then
    echo "intakt: the sample is not seeded yet" >&2
    exit 1
fi
