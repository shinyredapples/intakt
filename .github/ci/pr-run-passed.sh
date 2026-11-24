#!/bin/sh
# Prints yes when a run of ci.yml for a pull request into main passed with <sha> as that pull request's head and tested the
# tree <tree>, else no. A pull request run tests GitHub's merge of the head into the base, not the head itself, so the check
# job of a pull request into main uploads an artifact named main-tested-tree-<the merge's tree>; a pull request into another
# branch records nothing, because its path filters compared the head with that branch and may have skipped an application
# change that main lacks. Only that tree counts, not how it came about: it is the head's tree when the head already
# contained main, as a fast-forward land does, or when the merge added nothing the head lacked. A run whose merge added
# anything recorded another tree and does not count. Only runs from this repository's own branches count: a fork's run
# skips every job behind the fork guard and still ends green.
# A run the API lists under a pull request into another branch does not count either; the list is empty after a merge, so
# an empty list says nothing.
# Needs GH_TOKEN with actions: read and GITHUB_REPOSITORY. A failing API call exits non-zero, never prints no or yes.
set -eu
# An empty argument (a failed command substitution in the caller) must not ask about the record of an empty tree.
if [ "$#" -ne 2 ] || [ -z "$1" ] || [ -z "$2" ]; then
    echo "usage: pr-run-passed.sh <sha> <tree>" >&2
    exit 2
fi
# --paginate reads every page: a qualifying run or record past the first page still counts.
runs=$(gh api --paginate "repos/$GITHUB_REPOSITORY/actions/workflows/ci.yml/runs?head_sha=$1&event=pull_request&status=success&per_page=100" \
    --jq ".workflow_runs[] | select(.head_repository.full_name == \"$GITHUB_REPOSITORY\" and all(.pull_requests[]?; .base.ref == \"main\")) | .id")
for run in $runs; do
    names=$(gh api --paginate "repos/$GITHUB_REPOSITORY/actions/runs/$run/artifacts?name=main-tested-tree-$2&per_page=100" --jq '.artifacts[] | select(.expired | not) | .name')
    # One name per line, read whole: a name with a space must not match on one of its words.
    while IFS= read -r name; do
        if [ "$name" = "main-tested-tree-$2" ]; then
            echo yes
            exit 0
        fi
    done <<NAMES
$names
NAMES
done
echo no
