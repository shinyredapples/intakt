#!/bin/sh
# The build job's command: the builder works in a clone of its own, and only a bundle and the pull request text leave the job.
# Usage: build.sh <build clone> <prompt file> <branch> <model> <max turns> <allowance seconds> <gates at once> <tool time limit seconds>
# Copies the build clone's branches into TMPDIR, runs Claude Code there with the prompt on its input, then bundles the branch's new commits.
# Each of the session's tool calls may run up to <tool time limit seconds>, so the builder's own bin/check ends inside its call instead of going to the background.
# The builder's bin/check runs <gates at once> gates at the same time, a count the orchestrator hands over only when it is below what bin/check picks from the job's limits; when it is empty, INTAKT_GATE_SLOTS is left as the job's environment has it (unset unless the image sets it), so bin/check uses the job's limits (its CPUs and memory) to size its gates at once itself.
# The session ends at its turn limit or when the allowance is used up, and the script outlives it, so what it committed before is still bundled.
# Writes build.log, handback.bundle (only when the branch has new commits), pull-request.md (only when PR.md is a regular file) and question.json (only when QUESTION.json is a regular file) into INTAKT_JOB_OUTPUT.
set -u

source="$1"
prompt="$2"
branch="$3"
model="$4"
turns="$5"
allowance="$6"
gates="$7"
tool_time_limit="$8"

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

# Bounded because the builder can leave state git waits on that is not a setting, such as a named pipe at a ref or at packed-refs, which would hold the slot until the job is timed out;
# a bundle of the branch's new commits takes seconds, and the limit stays far below the 600 seconds the job keeps for this script after the allowance.
bundle_time_limit=30
# The form the bundle step uses, tried before the session: an image whose timeout is missing or rejects it would otherwise spend the session and then lose its commits.
# Never an unbounded bundle instead, which would let the builder hold the slot again.
cd "$clone" || exit 1
# Resolved from inside the clone and kept as an absolute path, because a relative PATH entry names another file here than at the bundle step.
bundle_timeout="$(command -v timeout 2>/dev/null)"
case "$bundle_timeout" in
    /*) ;;
    '') bundle_timeout='' ;;
    *) bundle_timeout="$PWD/$bundle_timeout" ;;
esac
if [ -z "$bundle_timeout" ] || ! "$bundle_timeout" -k 5 "$bundle_time_limit" true >> "$log" 2>&1; then
    echo 'the image'"'"'s timeout cannot bound the bundle step' >> "$log"
    exit 1
fi

base="$(git rev-parse HEAD)"
# The bytes of a QUESTION.json the checkout already holds, read before the session and kept in a variable the builder cannot write:
# the checkout's bytes and not the commit's, because a checkout that converts line endings holds other bytes than the commit.
# Hashed by sha256sum and not by git, because git's hash follows the clone's settings (its object format, its filters), which the builder can write.
# A hash that could not be taken is no baseline, because an empty one would make any question count as written, so none is handed back then.
question_before=''
question_hashed=1
if [ -f QUESTION.json ] && [ ! -L QUESTION.json ]; then
    if ! question_before="$(sha256sum 2>/dev/null < QUESTION.json)" || [ -z "$question_before" ]; then
        question_hashed=0
    fi
fi

# The egress allowlist lets only the vendor's API through, so the CLI's telemetry and update checks would only fail.
CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC=1
export CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC
# The variable bin/check reads for its gates at once, because the job's command line is the only way the orchestrator reaches the session.
if [ -n "$gates" ]; then
    INTAKT_GATE_SLOTS="$gates"
    export INTAKT_GATE_SLOTS
fi
# The CLI's default and its most for one command, both at the orchestrator's limit, so the builder gets that limit by default (it may still ask for less, or run a command in the background).
# Also passed as command-line settings, because the CLI applies the env of the clone's .claude/settings.json and .claude/settings.local.json after it starts,
# and only command-line settings rank above those files, so the clone cannot raise the limit.
BASH_DEFAULT_TIMEOUT_MS="$((tool_time_limit * 1000))"
BASH_MAX_TIMEOUT_MS="$BASH_DEFAULT_TIMEOUT_MS"
export BASH_DEFAULT_TIMEOUT_MS BASH_MAX_TIMEOUT_MS
time_limit_settings="$(printf '{"env":{"BASH_DEFAULT_TIMEOUT_MS":"%s","BASH_MAX_TIMEOUT_MS":"%s"}}' "$BASH_DEFAULT_TIMEOUT_MS" "$BASH_MAX_TIMEOUT_MS")"
# In a session of its own, so the processes it leaves running can be ended with it.
setsid claude -p --model "$model" --max-turns "$turns" --output-format json --dangerously-skip-permissions --settings "$time_limit_settings" < "$prompt" >> "$log" 2>&1 &
session=$!
# The allowance ends the session only, never this script, so a cut session still reaches the bundle below; the watchdog is in a session of its own too, so its sleep goes with it.
# It reports the cut by its exit status, not by a file, because the builder can write anywhere a file could be and so stop the kill or fake a cut.
# A TERM ends it without a cut only before it sends the kill: it then ends its own group, its sleep and itself, with a status that is no cut;
# it ignores TERM from before it sends the kill, so a watchdog that has cut still reports the cut when the TERM below reaches it.
# Its sleep runs in the background, because the shell runs a trap only once a command in the foreground has ended.
# It cuts only a session whose leader is still there: a leader killed from elsewhere is reaped by the wait below, so a watchdog that wakes before this script has sent its TERM
# does not take a surviving child of that session for a cut.
setsid sh -c 'trap '\''kill -s KILL 0'\'' TERM; sleep "$1" & wait "$!" && trap '\'''\'' TERM && kill -0 "$2" && kill -s KILL -- "-$2"' watchdog "$allowance" "$session" > /dev/null 2>&1 &
watchdog=$!
wait "$session"
status=$?
if [ "$status" -ne 137 ]; then
    # The watchdog's kill always ends the session with 137, so any other status rules out a cut; its group exists only once setsid has run, which a session that ends at once can beat, so the kill is tried again.
    for _ in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20; do
        kill -s KILL -- "-$watchdog" 2>/dev/null && break
        sleep 0.1
    done
else
    # Status 137 does not rule out a cut (the watchdog's own kill gives it), but a SIGKILL from elsewhere (such as the out-of-memory killer) gives it too, so the watchdog is asked to stop instead of waited out for the rest of the allowance; one that has already cut ignores the TERM.
    kill -s TERM "$watchdog" 2>/dev/null
fi
wait "$watchdog" 2>/dev/null
cut=$?

# A process the builder leaves running could write into the output after the cleanup below, so none is left.
# In the container this script is the first process of the job's own process namespace, so every other process there is the builder's,
# also one that left the session; elsewhere, as in the tests, only the session can be told apart from the user's other processes.
if [ "$$" -eq 1 ]; then
    kill -s KILL -1 2>/dev/null
else
    kill -s KILL -- "-$session" 2>/dev/null
fi

# The builder can write to the output folder too, and take its permissions away, so its access is restored before the log is written again.
# chmod follows a link, so a link the builder left is removed below without being touched.
chmod u+rwx -- "$output"
# A named pipe or a link in the log's place would make the first append wait or write elsewhere, so the log is started again, without what the builder replaced.
if [ ! -f "$log" ] || [ -L "$log" ]; then
    rm -rf -- "$log"
fi
printf 'claude exited with %d\n' "$status" >> "$log"
if [ "$cut" -eq 0 ]; then
    printf 'claude cut after %d seconds, its commits are kept\n' "$allowance" >> "$log"
fi

# The builder can write git's global and system settings too, for example an include that waits on a pipe or runs a command, so git no longer reads them.
GIT_CONFIG_GLOBAL=/dev/null
GIT_CONFIG_NOSYSTEM=1
export GIT_CONFIG_GLOBAL GIT_CONFIG_NOSYSTEM
# Git finds its repository by searching upward from a folder whose .git it cannot use, and the builder can make one in the work area above the clone,
# so the repository is named: relative, because the builder could put a link at the clone's path, never in place of the folder this script is in.
GIT_DIR=.git
GIT_WORK_TREE=.
export GIT_DIR GIT_WORK_TREE
# The same for the clone's own settings, which git reads in the clone, so they are replaced by the script's, written without git, which would read them;
# the format is the one read before the session, a commondir file would point git at another folder's settings, and a HEAD git cannot read would make .git no repository.
replace_settings() {
    # A link at .git would take the removal and the new files outside the clone, and the builder can take the permissions away too.
    if [ ! -d .git ] || [ -L .git ]; then
        echo '.git is not a folder of the clone' >&2
        return 1
    fi
    chmod u+rwx -- .git || return 1
    # Alternates tell git where else to read objects, so they are settings too; a link on their path would take the removal outside the clone.
    for folder in .git/objects .git/objects/info; do
        if [ -L "$folder" ]; then
            echo "$folder is not a folder of the clone" >&2
            return 1
        fi
        if [ -d "$folder" ]; then
            chmod u+rwx -- "$folder" || return 1
        fi
    done
    rm -rf -- .git/config .git/commondir .git/HEAD .git/objects/info/alternates .git/objects/info/http-alternates || return 1
    printf 'ref: refs/heads/%s\n' "$branch" > .git/HEAD || return 1
    if [ "$format" = sha1 ]; then
        printf '[core]\n\trepositoryformatversion = 0\n\tbare = false\n' > .git/config
    else
        printf '[core]\n\trepositoryformatversion = 1\n\tbare = false\n[extensions]\n\tobjectformat = %s\n' "$format" > .git/config
    fi
}
settings_replaced=1
if ! replace_settings >> "$log" 2>&1; then
    settings_replaced=0
fi

# Only what this script writes after the session is handed back.
for planted in "$output/pull-request.md" "$output/handback.bundle" "$output/question.json"; do
    if [ -d "$planted" ] && [ ! -L "$planted" ]; then
        chmod -R u+rwx -- "$planted"
    fi
done
rm -rf -- "$output/pull-request.md" "$output/handback.bundle" "$output/question.json"

# The orchestrator reads this exit code as no result, because a planted file left here would otherwise be handed back.
if [ -e "$output/pull-request.md" ] || [ -L "$output/pull-request.md" ] \
    || [ -e "$output/handback.bundle" ] || [ -L "$output/handback.bundle" ] \
    || [ -e "$output/question.json" ] || [ -L "$output/question.json" ]; then
    echo 'the output folder could not be cleared' >> "$log"
    exit 3
fi

# A link is not followed, because it could point the text at any file the job can read.
if [ -f PR.md ] && [ ! -L PR.md ]; then
    cp PR.md "$output/pull-request.md"
fi

# The same for the question, which says the builder stopped to ask instead of building;
# a QUESTION.json the checkout already held when the session started is not this builder's question, so it is handed back only when the session wrote it or changed its bytes.
# The same sha256sum of the file's bytes as before the session, so no git setting the builder wrote in the clone reaches the comparison.
# A hash that fails after the session is no change either, because only a hash that was taken can show other bytes.
# The failed baseline is logged whether or not the file is still there, because the builder can remove it.
if [ "$question_hashed" -eq 0 ]; then
    echo 'QUESTION.json is not handed back: it could not be hashed before the session' >> "$log"
elif [ -f QUESTION.json ] && [ ! -L QUESTION.json ]; then
    if ! question_after="$(sha256sum 2>/dev/null < QUESTION.json)" || [ -z "$question_after" ]; then
        echo 'QUESTION.json is not handed back: it could not be hashed after the session' >> "$log"
    elif [ "$question_after" != "$question_before" ]; then
        cp QUESTION.json "$output/question.json"
    fi
fi

# Settings the script could not replace may still be the builder's, which git would read, so no git command runs then.
if [ "$settings_replaced" -eq 0 ]; then
    echo 'no bundle is handed back: the clone'"'"'s git settings could not be replaced' >> "$log"
    exit 0
fi

# The kill follows the TERM, so a git that does not end on TERM is still ended; the short option, because a project's image may carry a timeout (such as BusyBox's) that has no long one.
# The branch by its full name, so a branch the builder checked out instead is never what is handed back; git refuses an empty bundle.
"$bundle_timeout" -k 5 "$bundle_time_limit" git bundle create --quiet "$output/handback.bundle" "refs/heads/$branch" "^$base" >> "$log" 2>&1
bundled=$?
# 124 is timeout's status for a cut, 137 its status once the kill was needed, and 143 that of a timeout that reports the status of the git its TERM ended instead of its own;
# the builder's processes are gone by now, so only the limit sends git these signals.
if [ "$bundled" -eq 124 ] || [ "$bundled" -eq 137 ] || [ "$bundled" -eq 143 ]; then
    printf 'no bundle is handed back: git bundle create was cut after %d seconds\n' "$bundle_time_limit" >> "$log"
fi
if [ "$bundled" -ne 0 ]; then
    # What a failed or cut bundle left is not the branch, so it is not handed back either; a killed git leaves its lock file too.
    rm -f -- "$output/handback.bundle" "$output/handback.bundle.lock"
    if [ -e "$output/handback.bundle" ] || [ -L "$output/handback.bundle" ]; then
        echo 'the output folder could not be cleared' >> "$log"
        exit 3
    fi
fi

# Not the CLI's status, which the log holds, so the CLI can never end the job with the code that means an output not cleared.
exit 0
