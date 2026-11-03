#!/bin/bash
set -euo pipefail

# Secrets arrive at run time (-e or --env-file), never in the image; fail here with a clear message instead of in the hub.
: "${APP_SECRET:?set APP_SECRET}"
: "${MERCURE_JWT_SECRET:?set MERCURE_JWT_SECRET}"

php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

web() {
    exec frankenphp run --config Caddyfile --adapter caddyfile
}

# Plain php, not frankenphp php-cli, until the SIGTERM probe of plan task T28 passes on FrankenPHP 1.13.4.
# The limits end a consumer on purpose, so what it leaks is dropped; the loop below starts it again.
async() {
    exec php bin/console messenger:consume async --memory-limit="${WORKER_MEMORY_CEILING_MB}M" --time-limit=3600
}

scheduler_watcher() {
    exec php bin/console messenger:consume scheduler_watcher --memory-limit="${WORKER_MEMORY_CEILING_MB}M" --time-limit=3600
}

declare -A running=()

start() {
    "$1" &
    running[$!]=$1
}

stop_all() {
    trap - TERM INT
    kill -TERM "${!running[@]}" 2>/dev/null || true
    # A consumer ignores SIGTERM until its worker exists (upstream/messenger-consume-ignores-early-sigterm), so what still runs after a grace period inside Docker's 10 s stop timeout is killed.
    for _ in {1..80}; do
        any_running || break
        sleep 0.1
    done
    kill -KILL "${!running[@]}" 2>/dev/null || true
    wait
    exit "$1"
}

any_running() {
    local pid
    for pid in "${!running[@]}"; do
        kill -0 "$pid" 2>/dev/null && return 0
    done
    return 1
}

trap 'stop_all 0' TERM INT

start web
for _ in $(seq "$MESSENGER_CONSUMERS"); do
    start async
done
start scheduler_watcher

while :; do
    ended=''
    status=0
    wait -n -p ended "${!running[@]}" || status=$?
    name=${running[$ended]}
    unset "running[$ended]"

    # The web server is not started again here, because FrankenPHP restarts its own workers and Docker's restart policy restarts the container.
    if [[ $name == web ]]; then
        echo "intakt: the web server ended with status $status, stopping" >&2
        stop_all "$status"
    fi

    echo "intakt: $name ended with status $status, starting it again" >&2
    sleep 1
    start "$name"
done
