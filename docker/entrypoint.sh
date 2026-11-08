#!/bin/bash
set -euo pipefail

# Secrets arrive at run time (-e or --env-file), never in the image; fail here with a clear message instead of in the hub.
: "${APP_SECRET:?set APP_SECRET}"
: "${MERCURE_JWT_SECRET:?set MERCURE_JWT_SECRET}"
# The Caddyfile reads MERCURE_JWT_ISSUER without a default, so a missing one is named here instead of ending the web process silently.
: "${MERCURE_JWT_ISSUER:?set MERCURE_JWT_ISSUER}"

php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# The cache directory is removed by hand, because cache:clear kept the image's container after the seed; the warmup runs in a new process so it reads the current keys and project file.
rebuild_container() {
    rm -rf "var/cache/${APP_ENV:-prod}"
    php bin/console cache:warmup --no-interaction
}

# Each start writes its own draft (mode 0600 from mktemp) and links it into place; the link fails when another start linked first, so starts at once all read the one complete token.
owner_token() {
    local draft
    if [[ ! -e $1 ]]; then
        draft=$(mktemp "$1.XXXXXX") || return 1
        od -An -N32 -tx1 /dev/urandom | tr -d ' \n' > "$draft" || { rm -f "$draft"; return 1; }
        ln "$draft" "$1" 2>/dev/null || true
        rm -f "$draft"
    fi
    cat "$1"
}

# Assigned apart from the export, because export would hide a failed write, and a start without the token would leave the printed MCP setup unusable.
export_owner_token() {
    local file token
    file="$(dirname "$INTAKT_CONFIG")/mcp-owner-token"
    token=$(owner_token "$file") || token=
    if [[ ! $token =~ ^[0-9a-f]{64}$ ]]; then
        echo "intakt: could not make or read the MCP owner token in $file; set MCP_OWNER_TOKEN or make its folder writable" >&2
        exit 1
    fi
    export MCP_OWNER_TOKEN=$token
}

# Set by compose.yaml for the demo. The image's container was built without the provider keys, which arrive only now, so it is built again before the seed reads them and again after it writes the project.
if [[ -n ${INTAKT_SAMPLE_REPOSITORY:-} ]]; then
    : "${INTAKT_CONFIG:?set INTAKT_CONFIG to the project file the sample project is written to}"
    # A token the owner set wins; otherwise one is made once and kept on the data volume, so the printed token keeps working after a restart. Writes are allowed for the demo, because its point is to start a review from a client.
    export MCP_OWNER_ACCESS="${MCP_OWNER_ACCESS:-mcp_tool_write}"
    [[ -n ${MCP_OWNER_TOKEN:-} ]] || export_owner_token
    rebuild_container
    php bin/console intakt:sample:seed "$INTAKT_SAMPLE_REPOSITORY" "$INTAKT_CONFIG"
    rebuild_container
fi

# Validating first prints why FrankenPHP rejects the Caddyfile, which the web process alone would end on without a reason.
frankenphp validate --config Caddyfile --adapter caddyfile

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
    # The job table, not running: bash runs this trap between the two commands of start, so a child can be started and not yet recorded, and the final wait would hang on it.
    local -a children
    mapfile -t children < <(jobs -p)
    kill -TERM "${children[@]}" 2>/dev/null || true
    # A consumer ignores SIGTERM until its worker exists (upstream/messenger-consume-ignores-early-sigterm), so what still runs after a grace period inside Docker's 10 s stop timeout is killed.
    for _ in {1..80}; do
        any_running "${children[@]}" || break
        sleep 0.1
    done
    kill -KILL "${children[@]}" 2>/dev/null || true
    wait
    exit "$1"
}

any_running() {
    local pid
    for pid in "$@"; do
        kill -0 "$pid" 2>/dev/null && return 0
    done
    return 1
}

restart() {
    local name=${running[$1]}
    unset "running[$1]"

    # The web server is not started again here, because FrankenPHP restarts its own workers and Docker's restart policy restarts the container.
    if [[ $name == web ]]; then
        echo "intakt: the web server ended with status $2, stopping" >&2
        stop_all "$2"
    fi

    echo "intakt: $name ended with status $2, starting it again" >&2
    start "$name"
}

# A child killed by a signal while a command runs is reaped by bash and unknown to wait -n ("no such job"), so every child is checked by pid and its status taken with a plain wait, which still has it.
restart_ended() {
    local pid status
    for pid in "${!running[@]}"; do
        kill -0 "$pid" 2>/dev/null && continue
        status=0
        wait "$pid" 2>/dev/null || status=$?
        restart "$pid" "$status"
    done
}

trap 'stop_all 0' TERM INT

start web
for _ in $(seq "$MESSENGER_CONSUMERS"); do
    start async
done
start scheduler_watcher

# A poll instead of wait -n, which blocks on the live children once it has forgotten a dead one; the second is also the pause before an ended consumer starts again, and the TERM trap runs after it, inside Docker's 10 s stop timeout.
while :; do
    sleep 1
    restart_ended
done
