#!/bin/sh
# The build job's command: the builder works in a clone of its own, and only a bundle and the pull request text leave the job.
# Usage: build.sh <build clone> <prompt file> <branch> <model>
# Copies the build clone's branches into TMPDIR, runs Claude Code there with the prompt on its input, then bundles the branch's new commits.
# Writes build.log, handback.bundle (only when the branch has new commits) and pull-request.md (only when PR.md is a regular file) into INTAKT_JOB_OUTPUT.
set -u

source="$1"
prompt="$2"
branch="$3"
model="$4"

output="${INTAKT_JOB_OUTPUT:-/output}"
clone="${TMPDIR:-/work}/clone"
log="$output/build.log"

# Init and fetch instead of a clone, so the clone holds the build clone's branches and source refs and no remote.
prepare() {
    # Git's answer must be the name itself, so a name git expands, or one that reads as an option, never reaches the commands below.
    [ "$(git check-ref-format --branch "$branch")" = "$branch" ] \
        && format="$(git -C "$source" rev-parse --show-object-format)" \
        && git init --quiet --initial-branch="$branch" --object-format="$format" -- "$clone" \
        && git -C "$clone" fetch --quiet --no-tags --no-write-fetch-head --update-head-ok -- "$source" '+refs/heads/*:refs/heads/*' '+refs/source/*:refs/source/*' \
        && git -C "$clone" checkout --quiet --force "$branch" \
        && git -C "$clone" config user.name 'Intakt builder' \
        && git -C "$clone" config user.email 'builder@intakt.invalid'
}

if ! prepare > "$log" 2>&1; then
    exit 1
fi

cd "$clone" || exit 1
base="$(git rev-parse HEAD)"

# The egress allowlist lets only the vendor's API through, so the CLI's telemetry and update checks would only fail.
CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC=1
export CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC
claude -p --model "$model" --output-format json --dangerously-skip-permissions < "$prompt" >> "$log" 2>&1
status=$?
printf 'claude exited with %d\n' "$status" >> "$log"

# The builder can write to the output folder too, so only what this script writes after the session is handed back.
rm -rf -- "$output/pull-request.md" "$output/handback.bundle"

# A link is not followed, because it could point the text at any file the job can read.
if [ -f PR.md ] && [ ! -L PR.md ]; then
    cp PR.md "$output/pull-request.md"
fi

# The branch by its full name, so a branch the builder checked out instead is never what is handed back; git refuses an empty bundle.
git bundle create --quiet "$output/handback.bundle" "refs/heads/$branch" "^$base" >> "$log" 2>&1

exit "$status"
