#!/bin/sh
# The dependency install job's command, so a snapshot comes from the lock files alone and never from a builder's tree.
# Usage: install-dependencies.sh <lock files> <kept> <directory> -- <install command>
# Copies the lock files into TMPDIR, runs the install command there with sh -c, then copies <directory> to the same path under <kept>.
# Writes install.log and install-result (installed) into INTAKT_JOB_OUTPUT; no install-result means nothing is kept.
set -u

lock="$1"
kept="$2"
directory="$3"
shift 3
if [ "${1-}" = '--' ]; then
    shift
fi

output="${INTAKT_JOB_OUTPUT:-/output}"
project="${TMPDIR:-/work}/project"
log="$output/install.log"

mkdir -p "$project" \
    && cp -R "$lock/." "$project/" \
    && cd "$project" \
    && sh -c "$1" > "$log" 2>&1 < /dev/null \
    && test -d "$directory" \
    && mkdir -p "$(dirname "$kept/$directory")" \
    && cp -R "$directory" "$kept/$directory" \
    && printf 'installed\n' > "$output/install-result"
