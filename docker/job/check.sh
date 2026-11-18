#!/bin/sh
# The check job's command, so the builder's own run of the checks is never what lets a change through.
# Usage: check.sh <mirror> <bundle> <branch> -- <step> [step ...]
# Clones the mirror into TMPDIR, applies the bundle's branch, merges current main (the mirror's HEAD) into it when the branch lacks it, then runs each step with sh -c in the clone until one fails.
# Writes check.log and check-result (passed, failed <exit code>, not-applied or conflicts-with-main) into INTAKT_JOB_OUTPUT, the job's only way to hand its result back.
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
main=''

apply() {
    git clone --quiet --no-hardlinks -- "$mirror" "$clone" || return 1
    # Read before the checkout moves HEAD; an empty mirror has no main to merge.
    main=$(git -C "$clone" rev-parse --verify --quiet HEAD) || main=''
    git -C "$clone" fetch --quiet --update-head-ok -- "$bundle" "+refs/heads/$branch:refs/heads/$branch" \
        && git -C "$clone" checkout --quiet --force "$branch"
}

# The check runs on what CI runs on, the branch merged with current main, because a change can fail only together with main's newer commits.
merge_main() {
    if [ -z "$main" ] || git merge-base --is-ancestor "$main" HEAD; then
        return 0
    fi
    printf '$ git merge %s\n' "$main"
    # The check's own identity on the command line, because the job's clone and HOME carry none.
    git -c user.name=intakt-check -c user.email=check@intakt.invalid merge --no-ff --no-edit "$main"
}

result() {
    printf '%s\n' "$1" > "$output/check-result"
}

if ! apply > "$log" 2>&1; then
    result 'not-applied'
    exit 1
fi

cd "$clone" || exit 1

if ! merge_main >> "$log" 2>&1; then
    result 'conflicts-with-main'
    exit 1
fi

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
