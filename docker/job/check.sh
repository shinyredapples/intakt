#!/bin/sh
# The check job's command, so the builder's own run of the checks is never what lets a change through.
# Usage: check.sh <mirror> <bundle> <branch> -- <step> [step ...]
# The result goes to files in INTAKT_JOB_OUTPUT, the job's only way to hand its result back.
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
merged=''

apply() {
    git clone --quiet --no-hardlinks -- "$mirror" "$clone" || return 1
    # Read before the checkout moves HEAD; an empty mirror has no main to merge.
    main=$(git -C "$clone" rev-parse --verify --quiet HEAD) || main=''
    git -C "$clone" fetch --quiet --update-head-ok -- "$bundle" "+refs/heads/$branch:refs/heads/$branch" \
        && git -C "$clone" checkout --quiet --force "$branch"
}

# The check runs on what CI runs on, the branch merged into current main, because a change can fail only together with main's newer commits.
merge_main() {
    if [ -z "$main" ] || git merge-base --is-ancestor "$main" HEAD; then
        return 0
    fi
    head=$(git rev-parse --verify HEAD) || return 1
    printf '$ git merge %s into %s\n' "$head" "$main"
    # Main as the first parent, as CI merges, because a merge driver such as union orders the two sides by it.
    git reset --quiet --hard "$main" || return 1
    # The check's own identity, because the job's clone and HOME carry none, and a conventional subject, because the commit-subject gate reads this commit too.
    # No hook and no signature, as CI's merge has neither: a refusing hook or a failing signer of the image would stop a merge whose trees merged cleanly.
    # LC_ALL=C, because the conflict test below reads git's English CONFLICT lines.
    merged=$(LC_ALL=C git -c core.hooksPath=/dev/null -c commit.gpgSign=false -c user.name=intakt-check -c user.email=check@intakt.invalid \
        merge --no-ff --no-edit -m 'chore(check): merge the branch into current main' "$head" 2>&1)
    status=$?
    printf '%s\n' "$merged"
    return "$status"
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
    # Only git's own conflict report means the branch has something to resolve, so a refused merge or a failed merge commit is not-merged; the CONFLICT line counts too, because a directory renamed to two places leaves no unmerged path.
    if [ -n "$(git diff --name-only --diff-filter=U)" ] || printf '%s\n' "$merged" | grep -q '^CONFLICT ('; then
        result 'conflicts-with-main'
    else
        result 'not-merged'
    fi
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
