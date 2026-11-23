#!/bin/sh
# Prints yes when a run of ci.yml for a pull request passed with <sha> as that pull request's head and tested the tree
# <tree>, else no. A pull request run tests GitHub's merge of the head into the base, not the head itself, so its check job
# uploads an artifact named tested-tree-<the merge's tree>. Only that tree counts, not how it came about: it is the head's
# tree when the head already contained the base, as a fast-forward land does, or when the merge added nothing the head
# lacked. A run whose merge added anything, from another target branch or a base the head did not contain, recorded
# another tree and does not count. Only runs from this repository's own branches count: a fork's run skips every job
# behind the fork guard and still ends green.
# Needs GH_TOKEN with actions: read and GITHUB_REPOSITORY. A failing API call exits non-zero, never prints no or yes.
set -eu
if [ "$#" -ne 2 ]; then
    echo "usage: pr-run-passed.sh <sha> <tree>" >&2
    exit 2
fi
# --paginate reads every page: a qualifying run or record past the first page still counts.
runs=$(gh api --paginate "repos/$GITHUB_REPOSITORY/actions/workflows/ci.yml/runs?head_sha=$1&event=pull_request&status=success&per_page=100" \
    --jq ".workflow_runs[] | select(.head_repository.full_name == \"$GITHUB_REPOSITORY\") | .id")
for run in $runs; do
    names=$(gh api --paginate "repos/$GITHUB_REPOSITORY/actions/runs/$run/artifacts?name=tested-tree-$2&per_page=100" --jq '.artifacts[] | select(.expired | not) | .name')
    # One name per line, read whole: a name with a space must not match on one of its words.
    while IFS= read -r name; do
        if [ "$name" = "tested-tree-$2" ]; then
            echo yes
            exit 0
        fi
    done <<NAMES
$names
NAMES
done
echo no
