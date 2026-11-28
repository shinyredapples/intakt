#!/bin/sh
# Opens the issue "Nightly CI failed on main" with <run link>, or, when one is open already, comments the link on it, so
# failed nightly runs collect in one issue. Run by the nightly-failed job of ci.yml after a failed scheduled or on-demand
# full run on main; it never blocks a merge.
# Needs GH_TOKEN with issues: write and GITHUB_REPOSITORY. A failing gh call exits non-zero, so the job fails visibly.
set -eu
if [ "$#" -ne 1 ] || [ -z "$1" ]; then
    echo "usage: nightly-failed-issue.sh <run link>" >&2
    exit 2
fi
title='Nightly CI failed on main'
body="The nightly full run of ci.yml on main failed: $1"
# Every page of open issues (pull requests excluded), matched on the whole title: the search index can lag behind a
# just-opened issue.
numbers=$(gh api --paginate "repos/$GITHUB_REPOSITORY/issues?state=open&per_page=100" \
    --jq ".[] | select(.pull_request == null and .title == \"$title\") | .number")
number=$(printf '%s\n' "$numbers" | head -n 1)
if [ -n "$number" ]; then
    gh issue comment "$number" --repo "$GITHUB_REPOSITORY" --body "$body"
    echo "commented on #$number"
else
    gh issue create --repo "$GITHUB_REPOSITORY" --title "$title" --body "$body"
fi
