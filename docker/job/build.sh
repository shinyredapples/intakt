#!/bin/sh
# The build job's command: the builder works in a clone of its own, and only a bundle and the pull request text leave the job.
# Usage: build.sh <build clone> <prompt file> <branch> <model> <max turns> <allowance seconds>
# Copies the build clone's branches into TMPDIR, runs Claude Code there with the prompt on its input, then bundles the branch's new commits.
# The session ends at its turn limit or when the allowance is used up, and the script outlives it, so what it committed before is still bundled.
# Writes build.log, handback.bundle (only when the branch has new commits) and pull-request.md (only when PR.md is a regular file) into INTAKT_JOB_OUTPUT.
set -u

source="$1"
prompt="$2"
branch="$3"
model="$4"
turns="$5"
allowance="$6"

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
# In a session of its own, so the processes it leaves running can be ended with it.
setsid claude -p --model "$model" --max-turns "$turns" --output-format json --dangerously-skip-permissions < "$prompt" >> "$log" 2>&1 &
session=$!
# The allowance ends the session only, never this script, so a cut session still reaches the bundle below; the watchdog is in a session of its own too, so its sleep goes with it.
# It reports the cut by its exit status, not by a file, because the builder can write anywhere a file could be and so stop the kill or fake a cut.
setsid sh -c 'sleep "$1" && kill -s KILL -- "-$2"' watchdog "$allowance" "$session" > /dev/null 2>&1 &
watchdog=$!
wait "$session"
status=$?
# A session ended by SIGKILL may be the watchdog's cut, which ending the watchdog now would hide; waiting for it ends at the allowance at the latest.
if [ "$status" -ne 137 ]; then
    # Its group exists only once setsid has run, which a session that ends at once can beat, so the kill is tried again.
    for _ in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20; do
        kill -s KILL -- "-$watchdog" 2>/dev/null && break
        sleep 0.1
    done
fi
wait "$watchdog" 2>/dev/null
cut=$?
printf 'claude exited with %d\n' "$status" >> "$log"
if [ "$cut" -eq 0 ]; then
    printf 'claude cut after %d seconds, its commits are kept\n' "$allowance" >> "$log"
fi

# A process the builder leaves running could write into the output after the cleanup below, so none is left.
# In the container this script is the first process of the job's own process namespace, so every other process there is the builder's,
# also one that left the session; elsewhere, as in the tests, only the session can be told apart from the user's other processes.
if [ "$$" -eq 1 ]; then
    kill -s KILL -1 2>/dev/null
else
    kill -s KILL -- "-$session" 2>/dev/null
fi

# The builder can write git's global and system settings too, for example an include that waits on a pipe or runs a command, so git no longer reads them.
GIT_CONFIG_GLOBAL=/dev/null
GIT_CONFIG_NOSYSTEM=1
export GIT_CONFIG_GLOBAL GIT_CONFIG_NOSYSTEM

# The builder can write to the output folder too, and take its permissions away, so only what this script writes after the session is handed back.
# chmod follows a link, so a link the builder left is removed without being touched.
chmod u+rwx -- "$output"
for planted in "$output/pull-request.md" "$output/handback.bundle"; do
    if [ -d "$planted" ] && [ ! -L "$planted" ]; then
        chmod -R u+rwx -- "$planted"
    fi
done
rm -rf -- "$output/pull-request.md" "$output/handback.bundle"

# The orchestrator reads this exit code as no result, because a planted file left here would otherwise be handed back.
if [ -e "$output/pull-request.md" ] || [ -L "$output/pull-request.md" ] \
    || [ -e "$output/handback.bundle" ] || [ -L "$output/handback.bundle" ]; then
    echo 'the output folder could not be cleared' >> "$log"
    exit 3
fi

# A link is not followed, because it could point the text at any file the job can read.
if [ -f PR.md ] && [ ! -L PR.md ]; then
    cp PR.md "$output/pull-request.md"
fi

# The branch by its full name, so a branch the builder checked out instead is never what is handed back; git refuses an empty bundle.
if ! git bundle create --quiet "$output/handback.bundle" "refs/heads/$branch" "^$base" >> "$log" 2>&1; then
    # What a failed bundle left is not the branch, so it is not handed back either.
    rm -f -- "$output/handback.bundle"
    if [ -e "$output/handback.bundle" ] || [ -L "$output/handback.bundle" ]; then
        echo 'the output folder could not be cleared' >> "$log"
        exit 3
    fi
fi

# Not the CLI's status, which the log holds, so the CLI can never end the job with the code that means an output not cleared.
exit 0
