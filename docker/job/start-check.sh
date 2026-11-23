#!/bin/sh
# Runs before every job's command, so a job that cannot do what it was told says so at once instead of failing halfway.
# Usage: start-check.sh mark [input path ...] -- command [argument ...]
# Reads INTAKT_JOB_OUTPUT (the output directory) and INTAKT_JOB_EGRESS (the allowlisted hosts, space-separated).
# Only shell built-ins, rm and curl, so it runs in any job image with a POSIX shell.
set -u
# Before any assignment, because bash as the image's sh turns allexport on when the image's environment sets SHELLOPTS=allexport, and would export the mark.
set +a
set -f

# Kept in an unexported variable and shifted off, so the job's command never sees it and cannot forge a line that carries it.
# Unset first, because a variable the image imports through its environment stays exported, and exec would hand the mark to the job.
unset intakt_mark
intakt_mark="${1:-}"
[ "$#" -gt 0 ] && shift
output="${INTAKT_JOB_OUTPUT:-/output}"
failed=''

# Standard error only, because the orchestrator reads the result from the container's log up to the mode line, which the job runs after and cannot rewrite.
log() {
    printf 'intakt-start-check %s: %s\n' "$intakt_mark" "$1" >&2
}

fail() {
    log "$1: $2"
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
