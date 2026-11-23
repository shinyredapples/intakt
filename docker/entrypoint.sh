#!/bin/bash
set -euo pipefail

# Each start writes its own draft (mode 0600 from mktemp) and links it into place; the link fails when another start linked first, so starts at once all read the one complete value.
kept_secret() {
    local draft
    if [[ ! -e $1 ]]; then
        draft=$(mktemp "$1.XXXXXX") || return 1
        od -An -N32 -tx1 /dev/urandom | tr -d ' \n' > "$draft" || { rm -f "$draft"; return 1; }
        ln "$draft" "$1" 2>/dev/null || true
        rm -f "$draft"
    fi
    cat "$1"
}

# A value set in the environment (-e or .env.local) wins; an empty one is made once and kept next to the project file on the data volume, so the sessions and tokens it signs survive a restart. Assigned apart from the export, because export would hide a failed write.
export_secret() {
    local name=$1 file value
    [[ -z ${!name:-} ]] || return 0
    if [[ -z ${INTAKT_CONFIG:-} ]]; then
        echo "intakt: set $name, or set INTAKT_CONFIG so a generated one can be kept next to the project file" >&2
        exit 1
    fi
    file="$(dirname "$INTAKT_CONFIG")/$2"
    value=$(kept_secret "$file") || value=
    if [[ ! $value =~ ^[0-9a-f]{64}$ ]]; then
        echo "intakt: could not make or read $name in $file; set $name or make its folder writable" >&2
        exit 1
    fi
    export "$name=$value"
}

export_secret APP_SECRET app-secret
export_secret MERCURE_JWT_SECRET mercure-jwt-secret
# Fail here with a clear message instead of in the hub.
: "${APP_SECRET:?set APP_SECRET}"
: "${MERCURE_JWT_SECRET:?set MERCURE_JWT_SECRET}"
# The Caddyfile reads MERCURE_JWT_ISSUER without a default, so a missing one is named here instead of ending the web process silently.
: "${MERCURE_JWT_ISSUER:?set MERCURE_JWT_ISSUER}"

# Read from the sample project's data file, so the seed, the key check and this start name one router; bash alone, because the value is one plain top-level line.
router_url() {
    # Quotes are refused, because YAML would strip them and this read would not.
    local line pattern="^router_url:[[:space:]]*([^[:space:]\"']+)[[:space:]]*\$"
    while IFS= read -r line || [[ -n $line ]]; do
        [[ $line =~ $pattern ]] && { echo "${BASH_REMATCH[1]}"; return 0; }
    done < config/sample-project.yaml
    return 1
}

# The Hugging Face router speaks the OpenAI API, so its token stands in for the openai_compatible variables unless the owner set either. Assigned apart from the export, because export would hide a failed read.
if [[ -n ${HF_TOKEN:-} && -z ${OPENAI_COMPATIBLE_BASE_URL:-} && -z ${OPENAI_COMPATIBLE_API_KEY:-} ]]; then
    router=$(router_url) || { echo "intakt: config/sample-project.yaml names no router_url, so HF_TOKEN cannot be used" >&2; exit 1; }
    export OPENAI_COMPATIBLE_BASE_URL=$router
    export OPENAI_COMPATIBLE_API_KEY=$HF_TOKEN
fi

READY_MARK=var/sample-ready
mkdir -p var
rm -f "$READY_MARK"

php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# Resolved as Symfony's kernel resolves APP_CACHE_DIR and APP_BUILD_DIR, so the folder removed is the one the kernel compiles into.
kernel_dir() {
    local dir=$1
    [[ $dir == /* ]] || dir="$PWD/$dir"
    echo "$dir/$2"
}

# The cache and build folders are removed by hand, because cache:clear kept the image's container after the seed; the warmup runs in a new process so it reads the current keys and project file.
rebuild_container() {
    local out env cache build
    local -a values
    # A plain assignment, so a read that fails ends the start here, before anything is removed.
    out=$(kernel_values)
    mapfile -t values <<< "$out"
    # The read prints each value behind its name; anything else (a line more or less, another order) would shift a value into another one's place, so nothing is removed then.
    if [[ ${#values[@]} -ne 3 || ${values[0]} != APP_ENV=* || ${values[1]} != APP_CACHE_DIR=* || ${values[2]} != APP_BUILD_DIR=* ]]; then
        echo "intakt: the read of the kernel's environment and folders did not print exactly APP_ENV, APP_CACHE_DIR and APP_BUILD_DIR, so its old container cannot be removed" >&2
        exit 1
    fi
    env=${values[0]#APP_ENV=}
    cache=${values[1]#APP_CACHE_DIR=}
    build=${values[2]#APP_BUILD_DIR=}
    if [[ -z $env || $env == */* ]]; then
        echo "intakt: could not read the kernel's environment, so its old container cannot be removed" >&2
        exit 1
    fi
    if [[ -n $cache ]]; then rm -rf "$(kernel_dir "$cache" "$env")"; else rm -rf "var/cache/$env"; fi
    [[ -z $build ]] || rm -rf "$(kernel_dir "$build" "$env")"
    php bin/console cache:warmup --no-interaction
}

# Prints APP_ENV, APP_CACHE_DIR and APP_BUILD_DIR as NAME=value, one per line, as bin/console sees them: Symfony Runtime loads the dotenv files with the runtime options bin/console gets (a dotenv_path, an APP_ENV a dotenv file sets), and a value in the environment wins. The environment is APP_ENV also when an env_var_name option names another variable, because bin/console passes APP_ENV to the kernel. A value with a control character ends the read before anything is printed, because a line break would read as two values and bash drops a NUL byte, so either would name another folder. Built from the options here instead of through vendor/autoload_runtime.php, which takes the project folder from where vendor really lies; the project folder is the working folder, as for bin/console in the image.
kernel_values() {
    php -r '
        require "vendor/autoload.php";
        $options = $_SERVER["APP_RUNTIME_OPTIONS"] ?? $_ENV["APP_RUNTIME_OPTIONS"] ?? [];
        if (is_string($options)) {
            $options = json_decode($options, true, 512, JSON_THROW_ON_ERROR);
        }
        $options += ["project_dir" => getcwd()];
        new Symfony\Component\Runtime\SymfonyRuntime($options);
        $lines = "";
        foreach (["APP_ENV", "APP_CACHE_DIR", "APP_BUILD_DIR"] as $name) {
            $value = $_SERVER[$name] ?? "";
            if (!is_string($value) || 1 === preg_match("/[\\x00-\\x1f\\x7f]/", $value)) {
                fwrite(STDERR, "intakt: $name holds a control character, so the folder it names cannot be read and nothing is removed\n");
                exit(1);
            }
            $lines .= "$name=$value\n";
        }
        echo $lines;
    '
}

# Assigned apart from the export, because export would hide a failed write, and a start without the token would leave the printed MCP setup unusable.
export_owner_token() {
    local file=${MCP_OWNER_KEPT_FILE:?set MCP_OWNER_KEPT_FILE to where the MCP owner token is kept} token
    token=$(kept_secret "$file") || token=
    if [[ ! $token =~ ^[0-9a-f]{64}$ ]]; then
        echo "intakt: could not make or read the MCP owner token in $file; set MCP_OWNER_TOKEN or make its folder writable" >&2
        exit 1
    fi
    export MCP_OWNER_TOKEN=$token
}

# The notes about providers without a key go to standard error, so discarding standard output leaves them in the start log; a mark on the data volume keeps a restart from printing them again.
# Notes that fail or a mark that cannot be written only print the notes again at the next start, which is no reason to stop this one; a failure leaves the mark unwritten for that retry.
print_provider_notes_once() {
    local mark
    mark="$(dirname "$INTAKT_CONFIG")/provider-notes-shown"
    [[ -e $mark ]] && return 0
    if ! php bin/console intakt:ai:tiers >/dev/null; then
        echo "intakt: could not print the provider notes; the next start tries again" >&2
        return 0
    fi
    touch "$mark" 2>/dev/null || true
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
    # Only when compose runs this service, because a plain docker run of the image with a sample (the CI smoke start) is not the compose demo and the command refuses there.
    [[ -z ${INTAKT_COMPOSE_SERVICE:-} ]] || php bin/console intakt:demo:create
    print_provider_notes_once
    # Set last, so the health check is green only once the sample is seeded; cleared above on every start, so a restart is not healthy before the seed has run again.
    touch "$READY_MARK"
# The project file is read only while the container is compiled, so every start builds it again before any worker acts on old projects: the image compiled its own without a project file, and a restart after the file was changed or removed still holds the projects the last start compiled.
else
    rebuild_container
fi

# Validating first prints why FrankenPHP rejects the Caddyfile, which the web process alone would end on without a reason.
frankenphp validate --config Caddyfile --adapter caddyfile

web() {
    exec frankenphp run --config Caddyfile --adapter caddyfile
}

# Plain php, not frankenphp php-cli, until the SIGTERM probe of plan task T28 passes on FrankenPHP 1.13.4.
# The limits end a consumer on purpose, so what it leaks is dropped; the loop below starts it again.
# Quiet, because nothing tells when a consumer has printed its start banner, which then followed the dashboard address; Symfony still prints an error at quiet verbosity.
async() {
    exec php bin/console messenger:consume async --memory-limit="${WORKER_MEMORY_CEILING_MB}M" --time-limit=3600 --quiet
}

scheduler_watcher() {
    exec php bin/console messenger:consume scheduler_watcher --memory-limit="${WORKER_MEMORY_CEILING_MB}M" --time-limit=3600 --quiet
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
    # A deadline, not a count of pauses, because on a loaded machine every pause overruns and 80 of them passed the stop timeout; read from the uptime, which a wall clock step cannot move (in hundredths of a second).
    local deadline=$(( $(uptime_ticks) + 800 ))
    while any_running "${children[@]}" && (( $(uptime_ticks) < deadline )); do
        sleep 0.1
    done
    kill -KILL "${children[@]}" 2>/dev/null || true
    wait
    exit "$1"
}

uptime_ticks() {
    local up
    read -r up _ < /proc/uptime
    echo $(( 10#${up/./} ))
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

# Bash runs the TERM trap only after a foreground command ends, so a call or pause that takes seconds would add them to the 8 s grace of stop_all and pass Docker's 10 s stop timeout; as a job it is waited for, because wait returns at once on a trapped signal, and stop_all ends it with the other jobs.
as_job() {
    "$@" &
    wait "$!"
}

# Asked from the poll below instead of waited for before it, so ended children still start again while the server comes up.
# Printed only once the health check and the dashboard answer, because an address opened earlier failed, and last, because the server's start output buried it.
# Two seconds at most per call, because a stuck server would otherwise hold the poll and with it the restart of an ended consumer; the stop does not wait for a call, because each runs as a job.
address_shown=
show_dashboard_address_once() {
    [[ -n ${INTAKT_SAMPLE_REPOSITORY:-} && -z $address_shown ]] || return 0
    as_job curl --fail --silent --max-time 2 --output /dev/null "http://${CADDY_ADMIN:-localhost:2019}/metrics" || return 0
    as_job curl --fail --silent --max-time 2 --output /dev/null "http://localhost:${DASHBOARD_PORT}/" || return 0
    address_shown=1
    echo "intakt: the dashboard is ready at http://localhost:${DASHBOARD_PORT}"
}

trap 'stop_all 0' TERM INT

start web
for _ in $(seq "$MESSENGER_CONSUMERS"); do
    start async
done
start scheduler_watcher

# A poll instead of wait -n, which blocks on the live children once it has forgotten a dead one; the second is also the pause before an ended consumer starts again, and it runs as a job, so the TERM trap runs at once instead of after it.
while :; do
    as_job sleep 1
    restart_ended
    show_dashboard_address_once
done
