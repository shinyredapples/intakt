#!/bin/sh
# The review job's command: one reviewer reads a read-only checkout of the exact head and reaches only its vendor through the job's proxy.
# Usage: review.sh <checkout> <head> <prompt file> <provider> <model> [<login file>]
# Refuses with exit 1, before the CLI runs, a git older than 2.40, a checkout that is a link or writable, carries a repository setting a clone does not write,
# is not at <head> or differs from it, and a job without the proxy; otherwise runs the provider's CLI (claude_code or codex) in the checkout.
# Writes review.log (refusals and the CLI's errors) and answer (the CLI's standard output) into INTAKT_JOB_OUTPUT.
set -u
set -f

output="${INTAKT_JOB_OUTPUT:-/output}"
log="$output/review.log"
: > "$log" || exit 1

refuse() {
    printf 'refused: %s\n' "$1" >> "$log"
    exit 1
}

if [ "$#" -ne 5 ] && [ "$#" -ne 6 ]; then
    refuse 'usage: review.sh <checkout> <head> <prompt file> <provider> <model> [<login file>]'
fi

checkout="$1"
head="$2"
prompt="$3"
provider="$4"
model="$5"
login="${6:-}"
index="${TMPDIR:-/work}/review-index"
hooks="${TMPDIR:-/work}/review-hooks"
attributes="${TMPDIR:-/work}/review-attributes"

# The image may export git variables (GIT_DIR, GIT_INDEX_FILE, GIT_CONFIG_COUNT and the like) and NODE_OPTIONS, which would point git elsewhere or load code into the CLI.
harden_environment() {
    for name in $(env | sed -n 's/^\(GIT_[A-Za-z0-9_]*\)=.*/\1/p'); do
        unset "$name"
    done
    unset NODE_OPTIONS
    GIT_CONFIG_GLOBAL=/dev/null
    GIT_CONFIG_NOSYSTEM=1
    GIT_ATTR_NOSYSTEM=1
    export GIT_CONFIG_GLOBAL GIT_CONFIG_NOSYSTEM GIT_ATTR_NOSYSTEM
}

# The job's internal network has no other way out, and with no bypass list every request of the CLI goes through the proxy.
require_the_proxy() {
    unset NO_PROXY no_proxy ALL_PROXY all_proxy
    proxy="${HTTPS_PROXY:-}"
    if [ -z "$proxy" ] || [ "${https_proxy:-}" != "$proxy" ] || [ "${HTTP_PROXY:-}" != "$proxy" ] || [ "${http_proxy:-}" != "$proxy" ]; then
        refuse 'the job has no egress proxy'
    fi
    if [ -z "${INTAKT_JOB_EGRESS:-}" ]; then
        refuse 'the job has no egress allowlist'
    fi
    if [ -n "${INTAKT_REDUCED_MODE:-}" ]; then
        refuse "the start check failed: $INTAKT_REDUCED_MODE"
    fi
}

# Pinned to the checkout, without its replace refs, which would make git compare the checkout against another commit's tree,
# with an index read from the head, because the checkout's own index could hide a change behind its flags or file times,
# with an empty hooks folder, because the checkout's .git/hooks would run on read-tree, and with an empty attributes file in place of
# the user's (under XDG_CONFIG_HOME or HOME, read even with GIT_CONFIG_GLOBAL set), whose rules could let a changed file compare equal.
checkout_git() {
    GIT_INDEX_FILE="$index" git --git-dir="$checkout/.git" --work-tree="$checkout" --no-replace-objects --no-optional-locks \
        -c core.untrackedCache=false -c core.hooksPath="$hooks" -c core.attributesFile="$attributes" "$@"
}

# Made anew, so nothing the job folder held before can stand in for them.
prepare_empty_git_files() {
    { rm -rf -- "$hooks" "$attributes" && mkdir -- "$hooks" && : > "$attributes"; } 2>> "$log" \
        || refuse 'the job folder cannot hold the empty hooks folder and attributes file'
}

# status needs --attr-source (git 2.40), and an older git would refuse every review with a reason that does not name the cause.
require_a_recent_git() {
    version="$(git --version 2>> "$log")"
    major="$(printf '%s\n' "$version" | sed -n 's/^git version \([0-9][0-9]*\)\.[0-9][0-9]*.*/\1/p')"
    minor="$(printf '%s\n' "$version" | sed -n 's/^git version [0-9][0-9]*\.\([0-9][0-9]*\).*/\1/p')"
    if [ -z "$major" ] || [ -z "$minor" ] || [ "$major" -lt 2 ] || { [ "$major" -eq 2 ] && [ "$minor" -lt 40 ]; }; then
        refuse "the checkout checks need git 2.40 or later, found: $version"
    fi
}

require_a_read_only_checkout() {
    # find does not descend into a link it is given, so a link to a folder would hide every writable entry in it; the job mounts a real folder.
    if [ -L "$checkout" ]; then
        refuse 'the checkout is a link'
    fi
    if [ ! -d "$checkout/.git" ] || [ -L "$checkout/.git" ]; then
        refuse 'the checkout has no .git folder of its own'
    fi
    # Every file and folder, because a writable file stays writable inside a read-only folder; an entry that cannot be checked refuses too.
    find "$checkout" -exec sh -c 'for path do [ ! -w "$path" ] || exit 1; done' intakt-review {} + 2>> "$log" \
        || refuse 'the checkout is writable'
}

# Only what git clone or git init writes on Linux, because any other setting (an fsmonitor, hooks, filters, an include,
# attributes such as ident that let a changed file compare equal) could run a command or hide a change.
require_only_clone_settings() {
    for file in commondir config.worktree objects/info/alternates info/attributes; do
        if [ -e "$checkout/.git/$file" ] || [ -L "$checkout/.git/$file" ]; then
            refuse "the checkout has .git/$file"
        fi
    done
    if [ ! -f "$checkout/.git/config" ] || [ -L "$checkout/.git/config" ]; then
        refuse 'the checkout has no regular .git/config'
    fi
    settings="$(git config --file "$checkout/.git/config" --list)" || refuse 'the checkout settings cannot be read'
    printf '%s\n' "$settings" | while IFS= read -r setting; do
        case "$setting" in
            core.repositoryformatversion=0 | core.repositoryformatversion=1 | core.filemode=true | core.bare=false \
                | core.logallrefupdates=true | extensions.objectformat=sha1 | extensions.objectformat=sha256 \
                | remote.origin.url=* | remote.origin.fetch=* | branch.*.remote=* | branch.*.merge=*) ;;
            *) exit 1 ;;
        esac
    done || refuse 'the checkout has a setting a clone does not write'
}

require_the_head() {
    case "$head" in
        *[!0-9a-f]* | '') refuse 'the head is not a commit id' ;;
    esac
    if [ "${#head}" -ne 40 ] && [ "${#head}" -ne 64 ]; then
        refuse 'the head is not a commit id'
    fi
    if [ "$(checkout_git rev-parse --verify --quiet 'HEAD^{commit}' 2>> "$log")" != "$head" ]; then
        refuse "the checkout is not at $head"
    fi
}

is_an_empty_folder() {
    [ -d "$1" ] && [ ! -L "$1" ] && entries="$(find "$1/." ! -name . -prune -print 2>> "$log")" && [ -z "$entries" ]
}

# A clone leaves every folder the head holds as a submodule (a gitlink) empty. The checks after this one do not look inside such a folder,
# so a file planted there would stay hidden, and git status starts git in a repository planted there, which runs that repository's fsmonitor.
require_empty_submodule_folders() {
    tab="$(printf '\t')"
    staged="$(checkout_git -c core.quotePath=false ls-files --stage 2>> "$log")" || refuse 'the head cannot be read'
    # The loop is a subshell of its own, so a refusal ends only the loop, hence the exit after it.
    printf '%s\n' "$staged" | sed -n "s/^160000 [^$tab]*$tab//p" | while IFS= read -r path; do
        case "$path" in
            # Not the folder's name but git's quoted form of it, and an empty folder with the quoted name could stand in for the real one.
            \"*) refuse 'the head has a submodule path with a character git quotes' ;;
        esac
        is_an_empty_folder "$checkout/$path" || refuse 'the checkout has a submodule folder that is not an empty folder'
    done || exit 1
}

# Untracked files are listed against the head's own .gitignore files only, never info/exclude; a .gitignore that is not the head's
# could ignore itself and what it hides, so git listing one among the ignored files refuses the checkout too.
require_no_change() {
    rm -f -- "$index"
    checkout_git read-tree HEAD 2>> "$log" || refuse 'the head cannot be read'
    require_empty_submodule_folders
    # Attributes from the empty tree, not the head's .gitattributes, so no ident, eol or encoding rule can make changed content compare equal.
    empty_tree="$(checkout_git hash-object -t tree --stdin < /dev/null 2>> "$log")" || refuse 'the head cannot be read'
    changes="$(checkout_git --attr-source="$empty_tree" status --porcelain --untracked-files=no --ignore-submodules=none 2>> "$log")" || refuse 'the checkout status cannot be read'
    if [ -n "$changes" ]; then
        refuse 'the checkout differs from the head'
    fi
    untracked="$(checkout_git ls-files --others --exclude-per-directory=.gitignore 2>> "$log")" || refuse 'the untracked files cannot be listed'
    if [ -n "$untracked" ]; then
        refuse 'the checkout has untracked files'
    fi
    ignored="$(checkout_git ls-files --others --ignored --directory --exclude-per-directory=.gitignore 2>> "$log")" || refuse 'the ignored files cannot be listed'
    if printf '%s\n' "$ignored" | grep -Eq '(^|/)\.gitignore"?$'; then
        refuse 'the checkout has a .gitignore the head does not hold'
    fi
    rm -f -- "$index"
}

# From the file into the CLI's environment alone, never on a command line; an API key or another address of the image would win over the login.
use_the_login() {
    if [ -n "$login" ]; then
        unset ANTHROPIC_API_KEY ANTHROPIC_AUTH_TOKEN ANTHROPIC_BASE_URL
        CLAUDE_CODE_OAUTH_TOKEN="$(cat -- "$login")"
        export CLAUDE_CODE_OAUTH_TOKEN
    fi
}

# Only file reads for Claude Code, and no settings, so neither the head's settings nor a hook can give it more.
run_claude_code() {
    use_the_login
    CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC=1
    export CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC
    claude -p --model "$model" --output-format stream-json --verbose --tools Read,Grep,Glob --allowedTools Read,Grep,Glob \
        --setting-sources '' --strict-mcp-config --no-session-persistence
}

# Codex enables its shell and other tools by default, so each is switched off by name, as for the in-process Codex reviewer.
run_codex() {
    codex exec --json --sandbox read-only --ephemeral --ignore-user-config --ignore-rules \
        --disable shell_tool --disable unified_exec --disable apps --disable plugins --disable browser_use \
        --disable in_app_browser --disable computer_use --disable multi_agent --disable image_generation \
        --disable view_image --disable hooks --model "$model" --config 'web_search="disabled"' -
}

harden_environment
require_the_proxy
case "$provider" in
    claude_code | codex) ;;
    *) refuse "no reviewer CLI for the provider $provider" ;;
esac
if [ ! -f "$prompt" ] || [ ! -r "$prompt" ]; then
    refuse 'the prompt cannot be read'
fi
if [ "$#" -eq 6 ] && { [ ! -f "$login" ] || [ ! -r "$login" ] || [ ! -s "$login" ]; }; then
    refuse 'the login cannot be read'
fi
require_a_recent_git
prepare_empty_git_files
require_a_read_only_checkout
require_only_clone_settings
require_the_head
require_no_change

# The redirections are opened before the subshell enters the checkout, so a relative path names the same file as above.
(
    cd "$checkout" || exit 1
    if [ "$provider" = claude_code ]; then
        run_claude_code
    else
        run_codex
    fi
) < "$prompt" > "$output/answer" 2>> "$log"
printf '%s exited with %d\n' "$provider" "$?" >> "$log"

# Not the CLI's status, which the log holds, so the CLI can never end the job with the code of a refused checkout.
exit 0
