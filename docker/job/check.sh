#!/bin/sh
# The check job's command, so the builder's own run of the checks is never what lets a change through.
# Usage: check.sh <mirror> <bundle> <branch> -- <step> [step ...]
# Clones the mirror into TMPDIR, applies the bundle's branch, then runs each step with sh -c in the clone until one fails.
# Writes check.log and check-result (passed, failed <exit code> or not-applied) into INTAKT_JOB_OUTPUT, the job's only way to hand its result back.
set -u

mirror="$1"
bundle="$2"
branch="$3"
shift 3
if [ "${1-}" = '--' ]; then
    shift
fi

output="${INTAKT_JOB_OUTPUT:-/output}"
clone="${TMPDIR:-/work}/clone"
log="$output/check.log"

apply() {
    git clone --quiet --no-hardlinks -- "$mirror" "$clone" \
        && git -C "$clone" fetch --quiet --update-head-ok -- "$bundle" "+refs/heads/$branch:refs/heads/$branch" \
        && git -C "$clone" checkout --quiet --force "$branch"
}

result() {
    printf '%s\n' "$1" > "$output/check-result"
}

if ! apply > "$log" 2>&1; then
    result 'not-applied'
    exit 1
fi

cd "$clone" || exit 1

for step in "$@"; do
    printf '$ %s\n' "$step" >> "$log"
    sh -c "$step" >> "$log" 2>&1 < /dev/null
    status=$?
    if [ "$status" -ne 0 ]; then
        result "failed $status"
        exit "$status"
    fi
done

result 'passed'
