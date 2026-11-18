#!/bin/sh
# The check job's command, so the builder's own run of the checks is never what lets a change through.
# Usage: check.sh <mirror> <bundle> <branch> [<dependencies> <directory>] -- <step> [step ...]
# Clones the mirror into TMPDIR, applies the bundle's branch, copies <directory> of the dependency snapshot into the clone when one is given
# and the head leaves that path free (nothing at it, and no file or link at a parent of it), then runs each step with sh -c in the clone until one fails.
# Writes check.log and check-result (passed, failed <exit code> or not-applied) into INTAKT_JOB_OUTPUT, the job's only way to hand its result back.
set -u

mirror="$1"
bundle="$2"
branch="$3"
shift 3
dependencies=''
directory=''
if [ $# -gt 1 ] && [ "$1" != '--' ]; then
    dependencies="$1"
    directory="$2"
    shift 2
fi
if [ "${1-}" = '--' ]; then
    shift
fi

output="${INTAKT_JOB_OUTPUT:-/output}"
clone="${TMPDIR:-/work}/clone"
log="$output/check.log"

apply() {
    git clone --quiet --no-hardlinks -- "$mirror" "$clone" \
        && git -C "$clone" fetch --quiet --update-head-ok -- "$bundle" "+refs/heads/$branch:refs/heads/$branch" \
        && git -C "$clone" checkout --quiet --force "$branch" \
        && copy_dependencies
}

# A copy, so the check writes its own autoload files into the clone and the read-only snapshot stays as the runner saved it.
# Only into a path the head leaves free, so the snapshot never replaces a file of the change under check; such a head installs as before.
copy_dependencies() {
    if [ -z "$dependencies" ]; then
        return 0
    fi
    test -d "$dependencies/$directory" || return 1
    if [ -e "$clone/$directory" ] || [ -L "$clone/$directory" ] || parent_blocked; then
        return 0
    fi
    mkdir -p "$(dirname "$clone/$directory")" \
        && cp -R "$dependencies/$directory" "$clone/$directory"
}

# A file the head tracks at a parent would fail the mkdir, and a link there would lead the copy to wherever the head points it.
# The link test comes first, because a link to a directory also passes -d.
parent_blocked() {
    parent="$clone"
    below="$directory"
    while [ "$below" != "${below#*/}" ]; do
        name="${below%%/*}"
        below="${below#*/}"
        if [ -z "$name" ]; then
            continue
        fi
        parent="$parent/$name"
        if [ -L "$parent" ]; then
            return 0
        fi
        if [ -e "$parent" ] && [ ! -d "$parent" ]; then
            return 0
        fi
    done
    return 1
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
