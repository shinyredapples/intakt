#!/bin/sh
# The install job's command: the locked install from the lock files alone, so no other file of the tree and no step a builder wrote runs before Intakt keeps the install.
# Usage: install.sh <lock folder> <probe> <install> <lock file> [lock file ...]
# The platform, the log and the result go to INTAKT_JOB_OUTPUT; the install, without the lock files, goes to INTAKT_JOB_KEPT.
set -u

lock="$1"
probe="$2"
install="$3"
shift 3

output="${INTAKT_JOB_OUTPUT:-/output}"
kept="${INTAKT_JOB_KEPT:-/kept}"
work="${TMPDIR:-/work}/install"
log="$output/install.log"

result() {
    printf '%s\n' "$1" > "$output/install-result"
}

# Read in the same job, so a save is refused when the image's platform changed after the key was made.
if ! sh -c "$probe" > "$output/platform" 2>> "$log" < /dev/null; then
    result 'no-platform'
    exit 1
fi

if ! mkdir -p "$work" || ! cp -R "$lock/." "$work/"; then
    result 'not-prepared'
    exit 1
fi

if ! cd "$work"; then
    result 'not-prepared'
    exit 1
fi

printf '$ %s\n' "$install" >> "$log"
sh -c "$install" >> "$log" 2>&1 < /dev/null
status=$?
if [ "$status" -ne 0 ]; then
    result "failed $status"
    exit "$status"
fi

# A lock file left behind would make the kept install claim lock inputs it was not keyed by.
for file in "$@"; do
    if ! rm -f -- "$work/$file" || [ -e "$work/$file" ] || [ -L "$work/$file" ]; then
        result 'not-kept'
        exit 1
    fi
done

# Nothing left means the install went elsewhere, so an empty folder is never saved as the install of these lock files.
if [ -z "$(ls -A .)" ]; then
    result 'not-kept'
    exit 1
fi

# Owner-writable before the copy, because an archive can hold a folder without permissions, which would stop the orchestrator's save and its cleanup.
if ! chmod -R u+rwX . || ! cp -R ./. "$kept/"; then
    result 'not-kept'
    exit 1
fi

result 'installed'
