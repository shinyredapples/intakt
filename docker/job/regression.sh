#!/bin/sh
# The regression job's command: one run of the tests a head adds or changes, on one revision.
# Usage: regression.sh <mirror> <bundle> <branch> <revision> <overlay branch or -> <overlay count> <install step or -> <overlay path> ... <test step> ...
# The result goes to files in INTAKT_JOB_OUTPUT, the job's only way to hand its result back.
set -u

mirror="$1"
bundle="$2"
branch="$3"
revision="$4"
overlay_from="$5"
overlay_count="$6"
install_step="$7"
shift 7

output="${INTAKT_JOB_OUTPUT:-/output}"
clone="${TMPDIR:-/work}/clone"
log="$output/regression.log"

own_git() (
    # The C locale, because in a UTF-8 one sed's pattern stops at a byte that is no text and leaves it on the name.
    for name in $(env | LC_ALL=C sed -n 's/^\(GIT_[A-Za-z0-9_]*\)=.*/\1/p'); do
        unset "$name"
    done
    GIT_CONFIG_NOSYSTEM=1
    GIT_CONFIG_GLOBAL=/dev/null
    GIT_ATTR_NOSYSTEM=1
    export GIT_CONFIG_NOSYSTEM GIT_CONFIG_GLOBAL GIT_ATTR_NOSYSTEM
    git -c core.attributesFile=/dev/null "$@"
)

result() {
    printf '%s\n' "$1" > "$output/regression-result"
}

apply() {
    (
        INTAKT_MIRROR="$mirror"
        export INTAKT_MIRROR
        own_git -c safe.directory="$mirror" clone --template= --quiet --no-hardlinks \
            --upload-pack='git -c safe.directory="$INTAKT_MIRROR" upload-pack' -- "$mirror" "$clone"
    ) || return 1
    own_git -C "$clone" fetch --quiet --update-head-ok -- "$bundle" "+refs/heads/$branch:refs/heads/$branch" \
        && own_git -C "$clone" checkout --quiet --force "$revision" --
}

if ! apply > "$log" 2>&1; then
    result 'not-applied'
    exit 1
fi

# The head's version of each test over the base's tree, so the base run shows whether the tests catch the bug the code still has.
count="$overlay_count"
if [ "$overlay_from" != '-' ]; then
    while [ "$count" -gt 0 ]; do
        # Literal pathspecs, because a test path such as tests/Example[2]Test.php would otherwise also lay tests/Example2Test.php over the base.
        own_git --literal-pathspecs -C "$clone" checkout --quiet "refs/heads/$overlay_from" -- "$1" >> "$log" 2>&1 || { result 'not-applied'; exit 1; }
        shift
        count=$((count - 1))
    done
else
    shift "$overlay_count"
fi

cd "$clone" || exit 1

if [ "$install_step" != '-' ]; then
    printf '$ %s\n' "$install_step" >> "$log"
    if ! sh -c "$install_step" >> "$log" 2>&1 < /dev/null; then
        result 'not-installed'
        exit 1
    fi
fi

# A test step exits 0 when its tests ran and passed and 1 when a test failed; any other status (an error before an assertion, a skip, no test) shows no reproduction.
for step in "$@"; do
    printf '$ %s\n' "$step" >> "$log"
    sh -c "$step" >> "$log" 2>&1 < /dev/null
    status=$?
    case "$status" in
        0) ;;
        1) result 'failed'; exit 1 ;;
        *) result "errored $status"; exit "$status" ;;
    esac
done

result 'passed'
