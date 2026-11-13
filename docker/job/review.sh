#!/bin/sh
# The review job's command: one reviewer reads the exact head in a checkout it cannot change, and reaches only its vendor through the job's proxy.
# Usage: review.sh <checkout> <head> <prompt file> <cli> <model>
# Runs the CLI (claude or codex) in the checkout with the prompt on its input, only once the checkout is at <head> with nothing changed,
# cannot be written, the start check passed and every proxy setting names the job's proxy.
# Writes review.log, and answer (the CLI's standard output) only when the CLI ran, into INTAKT_JOB_OUTPUT.
# Exits with 0 when the CLI ran (its status is in review.log), 1 when the checkout is not the head, 2 when it can be written,
# 3 when the network is not the job's proxy alone, 4 when the start check failed, 64 for another CLI.
set -u

checkout="$1"
head="$2"
prompt="$3"
cli="$4"
model="$5"

output="${INTAKT_JOB_OUTPUT:-/output}"
log="$output/review.log"
# The address EgressProxy hands every job with an allowlist, so a setting that names anything else is not the job's proxy.
proxy='http://egress-proxy:3128'

# The review never commits, and an image's git settings could run a command or point git at another repository.
GIT_CONFIG_GLOBAL=/dev/null
GIT_CONFIG_NOSYSTEM=1
export GIT_CONFIG_GLOBAL GIT_CONFIG_NOSYSTEM

refuse() {
    printf '%s\n' "$2" >> "$log"
    exit "$1"
}

: > "$log"

case "$cli" in
    claude|codex) ;;
    *) refuse 64 "unknown reviewer CLI: $cli" ;;
esac

# A failed check means the checkout or the vendor may not be what the review was told, so no verdict is given on it.
if [ -n "${INTAKT_REDUCED_MODE-}" ]; then
    refuse 4 "the start check failed ($INTAKT_REDUCED_MODE), so the review does not run"
fi

# A full object name only, so a branch or a short name that git resolves to some other commit never counts as the head.
case "$head" in
    *[!0-9a-f]*) refuse 1 "$head is not a full commit name" ;;
esac
if [ "${#head}" -ne 40 ] && [ "${#head}" -ne 64 ]; then
    refuse 1 "$head is not a full commit name"
fi

# Because git would otherwise find the repository of a parent folder and read that one's head.
if [ ! -e "$checkout/.git" ]; then
    refuse 1 "$checkout holds no repository"
fi

at="$(git -C "$checkout" rev-parse --verify --end-of-options 'HEAD^{commit}' 2>> "$log")"
if [ "$at" != "$head" ]; then
    refuse 1 "the checkout is at ${at:-no commit}, not at $head"
fi

# Without optional locks and without fsmonitor, so reading the status writes nothing and runs no command of the repository's settings.
if ! changes="$(git --no-optional-locks -C "$checkout" -c core.fsmonitor=false status --porcelain --untracked-files=all 2>> "$log")"; then
    refuse 1 "the status of the checkout cannot be read"
fi
if [ -n "$changes" ]; then
    refuse 1 "the checkout differs from $head"
fi
# git status takes a file marked skip-worktree or assume-unchanged as unchanged without reading it, so no file may carry either mark.
if ! entries="$(git --no-optional-locks -C "$checkout" -c core.fsmonitor=false ls-files -v 2>> "$log")"; then
    refuse 1 "the files of the checkout cannot be listed"
fi
while IFS= read -r entry; do
    case "$entry" in
        ''|'H '*) ;;
        *) refuse 1 "${entry#??} is marked so that git status does not compare it" ;;
    esac
done <<EOF
$entries
EOF

# The repository folder is probed too, because it can be a mount of its own.
for folder in "$checkout" "$checkout/.git"; do
    if [ -d "$folder" ] && (: > "$folder/.intakt-review-probe") 2> /dev/null; then
        rm -f -- "$folder/.intakt-review-probe"
        refuse 2 "$folder can be written, so the checkout is not read-only"
    fi
done

if [ -z "${INTAKT_JOB_EGRESS-}" ]; then
    refuse 3 'the job has no egress allowlist, so its vendor is not reached through the proxy'
fi
for name in HTTPS_PROXY https_proxy HTTP_PROXY http_proxy; do
    eval "value=\${$name-}"
    if [ "$value" != "$proxy" ]; then
        refuse 3 "$name is not the job's proxy $proxy"
    fi
done
# An image can set these, and a host named in them would be tried past the proxy.
unset NO_PROXY no_proxy ALL_PROXY all_proxy

cd "$checkout" || refuse 1 "$checkout cannot be entered"

# The egress allowlist lets only the vendor's API through, so the CLI's telemetry and update checks would only fail.
CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC=1
export CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC
case "$cli" in
    claude)
        claude -p --model "$model" --output-format json --tools 'Read,Glob,Grep' --setting-sources '' --strict-mcp-config --no-session-persistence \
            < "$prompt" > "$output/answer" 2>> "$log"
        ;;
    codex)
        # Its read-only sandbox stops writes, not commands, so every tool the CLI enables by default is off, as IsolatedCodexCli::DISABLED_FEATURES has it.
        codex exec --json --model "$model" --sandbox read-only --ephemeral --skip-git-repo-check --ignore-user-config --ignore-rules \
            --disable shell_tool --disable unified_exec --disable apps --disable plugins --disable browser_use --disable in_app_browser \
            --disable computer_use --disable multi_agent --disable image_generation --disable view_image --disable hooks \
            -c 'web_search="disabled"' - \
            < "$prompt" > "$output/answer" 2>> "$log"
        ;;
esac
status=$?
printf '%s exited with %d\n' "$cli" "$status" >> "$log"

# In the container this script is the job's first process, so any other process is the reviewer's, and one left running could still write the answer.
if [ "$$" -eq 1 ]; then
    kill -s KILL -1 2> /dev/null
fi

exit 0
