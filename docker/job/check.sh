#!/bin/sh
# The check job's command, so the builder's own run of the checks is never what lets a change through.
# Usage: check.sh <mirror> <bundle> <branch> [--dependencies <snapshot> <platform> <platform probe> <install step> <generate step> <lock file> <blob> [<lock file> <blob> ...]] -- <step> [step ...]
# The result goes to files in INTAKT_JOB_OUTPUT, the job's only way to hand its result back.
set -u

mirror="$1"
bundle="$2"
branch="$3"
shift 3
dependencies=''
platform=''
platform_probe=''
install_step=''
generate_step=''
copied=0
with_dependencies=0
if [ "${1-}" = '--dependencies' ]; then
    dependencies="$2"
    platform="$3"
    platform_probe="$4"
    install_step="$5"
    generate_step="$6"
    shift 6
    # Without the delimiter the lock files and the steps cannot be told apart, so no verdict is better than a pass that ran nothing.
    # Each argument on its own, because a step or lock file that only holds a -- closes nothing.
    for argument in "$@"; do
        if [ "$argument" = '--' ]; then
            with_dependencies=1
            break
        fi
    done
    if [ "$with_dependencies" -eq 0 ]; then
        printf 'the --dependencies option is not closed by --\n' >&2
        exit 1
    fi
fi
# The lock files stay in the arguments up to the --, because they are compared with the merged tree, which exists only after the merge.

output="${INTAKT_JOB_OUTPUT:-/output}"
clone="${TMPDIR:-/work}/clone"
log="$output/check.log"
main=''
merged=''

# Git reads no configuration of the image or job here, as in CI's test merge, so a merge option or signing setting cannot change the merge (subshell: steps keep the environment).
own_git() (
    # The C locale, because in a UTF-8 one sed's pattern stops at a byte that is no text and leaves it on the name.
    for name in $(env | LC_ALL=C sed -n 's/^\(GIT_[A-Za-z0-9_]*\)=.*/\1/p'); do
        unset "$name"
    done
    GIT_CONFIG_NOSYSTEM=1
    GIT_CONFIG_GLOBAL=/dev/null
    GIT_ATTR_NOSYSTEM=1
    export GIT_CONFIG_NOSYSTEM GIT_CONFIG_GLOBAL GIT_ATTR_NOSYSTEM
    # The user's attributes file is read even with GIT_CONFIG_GLOBAL set.
    git -c core.attributesFile=/dev/null "$@"
)

apply() {
    # No template, because git copies its default one of the image (its info/attributes, config and hooks) into the clone, which own_git does not shut out.
    # The mirror only is trusted whoever owns it, because git refuses to clone a local repository another user owns and the job's user need not own the mounted mirror.
    # The upload-pack that serves the clone gets it too, because git drops the clone's -c settings before it starts it; the path goes by a variable so the shell running it never parses the path.
    (
        INTAKT_MIRROR="$mirror"
        export INTAKT_MIRROR
        own_git -c safe.directory="$mirror" clone --template= --quiet --no-hardlinks \
            --upload-pack='git -c safe.directory="$INTAKT_MIRROR" upload-pack' -- "$mirror" "$clone"
    ) || return 1
    # Read before the checkout moves HEAD; an empty mirror has no main to merge.
    main=$(own_git -C "$clone" rev-parse --verify --quiet HEAD) || main=''
    own_git -C "$clone" fetch --quiet --update-head-ok -- "$bundle" "+refs/heads/$branch:refs/heads/$branch" \
        && own_git -C "$clone" checkout --quiet --force "$branch"
}

# The check runs on what CI runs on, the branch merged into current main, because a change can fail only together with main's newer commits.
merge_main() {
    if [ -z "$main" ] || own_git merge-base --is-ancestor "$main" HEAD; then
        return 0
    fi
    head=$(own_git rev-parse --verify HEAD) || return 1
    printf '$ git merge %s into %s\n' "$head" "$main"
    # Main as the first parent, as CI merges, because a merge driver such as union orders the two sides by it.
    own_git reset --quiet --hard "$main" || return 1
    # The check's own identity, because the job's clone and HOME carry none, and a conventional subject, because the commit-subject gate reads this commit too.
    # No hook, no signature and the merge's options named here, against settings in the clone's own configuration, which own_git does not shut out.
    # LC_ALL=C, because the conflict test below reads git's English CONFLICT lines.
    merged=$(LC_ALL=C own_git -c core.hooksPath=/dev/null -c commit.gpgSign=false -c user.name=intakt-check -c user.email=check@intakt.invalid \
        merge --no-ff --commit --no-squash --no-edit -m 'chore(check): merge the branch into current main' "$head" 2>&1)
    status=$?
    printf '%s\n' "$merged"
    if [ "$status" -ne 0 ]; then
        return "$status"
    fi
    # A merge can exit with 0 and leave no commit, and the steps would then run on main alone while reading history as if it held the branch.
    if [ "$(own_git rev-parse --verify --quiet 'HEAD^1')" != "$main" ] || [ "$(own_git rev-parse --verify --quiet 'HEAD^2')" != "$head" ]; then
        printf 'the merge left no merge commit of %s and %s\n' "$main" "$head"
        return 1
    fi
}

result() {
    printf '%s\n' "$1" > "$output/check-result"
}

# Runs its command on the name of each top-level entry of the snapshot, hidden ones included, and stops at the first that fails.
# Straight from the glob, never through a list split on line breaks, so a name holding one stays one name.
each_entry() {
    for entry in "$dependencies"/* "$dependencies"/.[!.]* "$dependencies"/..?*; do
        if [ -e "$entry" ] || [ -L "$entry" ]; then
            "$@" "${entry##*/}" || return 1
        fi
    done
}

seen_entry() {
    seen=1
}

not_in_tree() {
    if [ -e "$clone/$1" ] || [ -L "$clone/$1" ]; then
        held="$1"
        return 1
    fi
}

writable() {
    chmod -R u+w "$clone/$1"
}

remove_copy() {
    chmod -R u+w "$clone/$1" 2> /dev/null
    rm -rf -- "${clone:?}/$1" || return 1
    # Looked at again, because only an entry that is gone cannot mislead the install that follows.
    [ ! -e "$clone/$1" ] && [ ! -L "$clone/$1" ]
}

# The bytes of each lock file in the merged tree, unfiltered, must be the blob the snapshot's key was made of, because main can move after the snapshot was chosen and change what the install depends on (Composer's vendor-dir among it).
lock_files_match() {
    if [ "$#" -eq 0 ] || [ "$1" = '--' ]; then
        return 1
    fi
    while [ "$#" -gt 0 ] && [ "$1" != '--' ]; do
        if [ "$#" -lt 2 ] || [ ! -f "$1" ] || [ -L "$1" ] || [ "$(own_git hash-object --no-filters -- "$1")" != "$2" ]; then
            return 1
        fi
        shift 2
    done
}

# Copied writable into the clone, so the gate's generate step finds the locked packages in place and makes only the files that depend on this tree.
use_dependencies() {
    if ! lock_files_match "$@"; then
        printf 'the merged tree does not hold the lock files the snapshot was made for, so the check installs its dependencies itself\n'
        return 1
    fi
    # Read again in this job, because the probe job resolved the image on its own and a tag that moved since would copy a snapshot made for another platform.
    # Outside the tree, so no file of the change steers the probe.
    # Compared as hex through a file, because a command substitution drops NUL bytes.
    probe_output="${TMPDIR:-/work}/platform-probe"
    if ! (cd / && sh -c "$platform_probe" < /dev/null > "$probe_output"); then
        printf 'the platform probe failed in the check, so the check installs its dependencies itself\n'
        return 1
    fi
    # Each od's status is read, because two that fail print nothing and nothing equals nothing.
    if ! probed=$(od -An -v -tx1 < "$probe_output") || ! expected=$(printf '%s' "$platform" | od -An -v -tx1); then
        printf 'the platform of the check could not be compared with the one the snapshot was made for, so the check installs its dependencies itself\n'
        return 1
    fi
    if [ "$probed" != "$expected" ]; then
        printf 'the platform of the check is not the one the snapshot was made for, so the check installs its dependencies itself\n'
        return 1
    fi
    # A snapshot argument that is empty would make the entry glob read the root of the image.
    if [ -z "$dependencies" ] || [ ! -d "$dependencies" ]; then
        printf 'there is no dependency snapshot folder, so the check installs its dependencies itself\n'
        return 1
    fi
    seen=0
    each_entry seen_entry
    if [ "$seen" -eq 0 ]; then
        printf 'the dependency snapshot holds nothing, so the check installs its dependencies itself\n'
        return 1
    fi
    if ! each_entry not_in_tree; then
        printf 'the tree already holds %s, so the check installs its dependencies itself\n' "$held"
        return 1
    fi
    printf '$ copy the dependency snapshot %s\n' "$dependencies"
    if cp -R "$dependencies/." "$clone/" && each_entry writable; then
        return 0
    fi
    # A partial copy could name packages as installed that are not there, so nothing of it is kept.
    printf 'the dependency snapshot could not be copied\n'
    if ! each_entry remove_copy; then
        printf 'the partial copy of the dependency snapshot could not be removed, so the check stops without a result\n'
        return 2
    fi
    printf 'the partial copy of the dependency snapshot was removed, so the check installs its dependencies itself\n'
    return 1
}

if ! apply > "$log" 2>&1; then
    result 'not-applied'
    exit 1
fi

cd "$clone" || exit 1

if ! merge_main >> "$log" 2>&1; then
    # Only git's own conflict report means the branch has something to resolve, so a refused merge, a failed merge commit or a merge that left no commit is not-merged; the CONFLICT line counts too, because a directory renamed to two places leaves no unmerged path.
    if [ -n "$(own_git diff --name-only --diff-filter=U)" ] || printf '%s\n' "$merged" | grep -q '^CONFLICT ('; then
        result 'conflicts-with-main'
    else
        result 'not-merged'
    fi
    exit 1
fi

if [ "$with_dependencies" -eq 1 ]; then
    use_dependencies "$@" >> "$log" 2>&1
    case "$?" in
        0) copied=1 ;;
        # No step and no result, because an install on top of leftover package metadata could pass or fail for what the tree does not hold.
        2) exit 1 ;;
    esac
    while [ "$#" -gt 0 ] && [ "$1" != '--' ]; do
        shift
    done
fi
if [ "${1-}" = '--' ]; then
    shift
fi

for step in "$@"; do
    # The gate's own install only, because the copied snapshot already holds what it installs and the generate step makes the files that depend on this tree.
    if [ "$copied" -eq 1 ] && [ "$step" = "$install_step" ]; then
        step="$generate_step"
    fi
    printf '$ %s\n' "$step" >> "$log"
    sh -c "$step" >> "$log" 2>&1 < /dev/null
    status=$?
    if [ "$status" -ne 0 ]; then
        result "failed $status"
        exit "$status"
    fi
done

result 'passed'
