#!/bin/sh
# The check job's command, so the builder's own run of the checks is never what lets a change through.
# Usage: check.sh <mirror> <bundle> <branch> [--dependencies <snapshot> <install step> <generate step> <lock file> <blob> [<lock file> <blob> ...]] -- <step> [step ...]
# The result goes to files in INTAKT_JOB_OUTPUT, the job's only way to hand its result back.
set -u

mirror="$1"
bundle="$2"
branch="$3"
shift 3
dependencies=''
install_step=''
generate_step=''
copied=0
if [ "${1-}" = '--dependencies' ]; then
    dependencies="$2"
    install_step="$3"
    generate_step="$4"
    shift 4
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
    rm -rf -- "${clone:?}/$1"
    return 0
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
    if ! each_entry not_in_tree; then
        printf 'the tree already holds %s, so the check installs its dependencies itself\n' "$held"
        return 1
    fi
    printf '$ copy the dependency snapshot %s\n' "$dependencies"
    if cp -R "$dependencies/." "$clone/" && each_entry writable; then
        return 0
    fi
    # A partial copy could name packages as installed that are not there, so nothing of it is kept.
    printf 'the dependency snapshot could not be copied, so the check installs its dependencies itself\n'
    each_entry remove_copy
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

if [ -n "$dependencies" ]; then
    if use_dependencies "$@" >> "$log" 2>&1; then
        copied=1
    fi
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
