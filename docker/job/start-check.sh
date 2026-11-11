#!/bin/sh
# Runs before every job's command, so a job that cannot do what it was told says so at once instead of failing halfway.
# Usage: start-check.sh [input path ...] -- command [argument ...]
# Reads INTAKT_JOB_OUTPUT (the output directory) and INTAKT_JOB_EGRESS (the allowlisted hosts, space-separated).
# Only shell built-ins, rm and curl, so it runs in any job image with a POSIX shell.
set -u
set -f

output="${INTAKT_JOB_OUTPUT:-/output}"
failed=''
lines=''

log() {
    printf 'intakt-start-check: %s\n' "$1" >&2
}

fail() {
    log "$1: $2"
    lines="$lines$1: $2
"
    case " $failed " in
        *" $1 "*) ;;
        *) failed="${failed:+$failed }$1" ;;
    esac
}

probe() {
    file="$2/.intakt-probe-$$"

    if ! (printf '%s\n' "$$" > "$file") 2>/dev/null; then
        fail "$1" "cannot write a probe file in $2"
        return
    fi

    back=''
    read -r back < "$file" 2>/dev/null
    rm -f "$file" 2>/dev/null

    if [ "$back" != "$$" ]; then
        fail "$1" "the probe file in $2 read back differs"
        return
    fi

    log "$1: ok"
}

input() {
    if [ ! -e "$1" ]; then
        fail input "$1 is missing"
    elif [ ! -r "$1" ] || { [ -d "$1" ] && [ ! -x "$1" ]; }; then
        fail input "$1 is not readable"
    else
        log "input: $1: ok"
    fi
}

# Any HTTP answer counts, because the check is that the proxy lets the job through, not what the host answers.
egress() {
    if ! command -v curl > /dev/null 2>&1; then
        fail egress "curl is missing, so $1 cannot be checked"
        return
    fi

    if curl --silent --output /dev/null --max-time 10 "https://$1/"; then
        log "egress: $1: ok"
    else
        fail egress "$1 is not reachable through the proxy (curl exit $?)"
    fi
}

probe output "$output"
probe work "${TMPDIR:-/work}"

while [ "$#" -gt 0 ] && [ "$1" != '--' ]; do
    input "$1"
    shift
done

for host in ${INTAKT_JOB_EGRESS:-}; do
    egress "$host"
done

# The report ends with a line of its own, so the orchestrator can tell a whole report from a cut one.
if ! (printf '%send\n' "$lines" > "$output/.intakt-start-check") 2>/dev/null; then
    log "output: cannot write the report"
fi

if [ -n "$failed" ]; then
    INTAKT_REDUCED_MODE="$failed"
    export INTAKT_REDUCED_MODE
    log "reduced mode: $failed"
else
    unset INTAKT_REDUCED_MODE
    log 'full mode'
fi

if [ "$#" -lt 2 ]; then
    log 'no command to run'
    exit 64
fi

shift
exec "$@"
