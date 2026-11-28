#!/bin/sh
# The builder's own check, mounted read-only into the build job: it repeats the refusals of the hand-back that plain git can decide in the clone, so the builder fixes them in the same session.
# Usage: sh /input/precheck.sh, in the clone itself (not a linked worktree, where PR.md is looked up elsewhere); build.sh exports INTAKT_BUILD_BRANCH and INTAKT_BUILD_BASE (the branch's commit before the session) for it.
# Prints one line per refusal and exits with 1, or prints "precheck clean" and exits with 0; exits with 2 when it cannot check.
# Every line is fixed text, the reason the orchestrator would give (PrecheckScriptTest pins each to its case), and nothing read from the clone, because the output goes back to the model.
# Advice only: the orchestrator's hand-back check reads the bundle itself and decides, so nothing reads this script's result.
set -u

branch="${INTAKT_BUILD_BRANCH:-}"
base="${INTAKT_BUILD_BASE:-}"
if [ -z "$branch" ] || [ -z "$base" ]; then
    echo 'precheck: the session'"'"'s branch and starting commit are not set, so nothing was checked'
    exit 2
fi

if ! top="$(git rev-parse --show-toplevel 2> /dev/null)" || ! cd "$top"; then
    echo 'precheck: run it inside the checkout, so nothing was checked'
    exit 2
fi

refused=0
refuse() {
    printf '%s\n' "$1"
    refused=1
}

# The branch by its full name, because only refs/heads/<branch> is bundled, whatever the checkout is on.
if ! head="$(git rev-parse --verify --quiet "refs/heads/$branch^{commit}")" || [ "$head" = "$base" ]; then
    refuse 'the build made no commit on the task'"'"'s branch'
else
    # Stricter than the hand-back on a first build, which accepts any start on main, because the builder is told to build on the branch as it received it.
    if ! git merge-base --is-ancestor "$base" "$head" 2> /dev/null \
        || [ -n "$(git rev-list --max-parents=0 --max-count=1 "$head" --not "$base" 2> /dev/null)" ]; then
        refuse 'the bundle is not a fast-forward of the pushed branch, or does not start on main before the first push'
    fi
    # No build is asked to merge main yet, so every new merge commit is refused.
    if [ -n "$(git rev-list --min-parents=2 --max-count=1 "$head" --not "$base" 2> /dev/null)" ]; then
        refuse 'the bundle holds a merge commit, and no merge of main was asked'
    fi
    # Git refuses to bundle a commit whose objects it cannot traverse, so walk them as the bundle does.
    # The pack is written to nowhere: packing reads every blob, so a corrupt one fails here as it would in the bundle.
    if ! objects="$(git rev-list --objects "$head" --not "$base" 2> /dev/null)" \
        || ! printf '%s\n' "$objects" | git pack-objects --stdout > /dev/null 2>&1; then
        refuse 'the bundle holds a commit git would not write'
    fi
fi

# A link is not handed back, because build.sh copies PR.md only when it is a regular file.
if [ ! -f PR.md ] || [ -L PR.md ]; then
    refuse 'the build wrote no pull request text to PR.md'
fi

if [ "$refused" -ne 0 ]; then
    exit 1
fi
echo 'precheck clean'
