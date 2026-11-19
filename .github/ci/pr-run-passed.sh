#!/bin/sh
# Prints yes when a run of ci.yml for a pull request passed on <sha> as that pull request's head, else no. Only runs from
# this repository's own branches count: a fork's run skips every job behind the fork guard and still ends green.
# Needs GH_TOKEN with actions: read and GITHUB_REPOSITORY. A failing API call exits non-zero, never prints no or yes.
set -eu
if [ "$#" -ne 1 ]; then
    echo "usage: pr-run-passed.sh <sha>" >&2
    exit 2
fi
count=$(gh api "repos/$GITHUB_REPOSITORY/actions/workflows/ci.yml/runs?head_sha=$1&event=pull_request&status=success" \
    --jq "[.workflow_runs[] | select(.head_repository.full_name == \"$GITHUB_REPOSITORY\")] | length")
if [ "$count" -gt 0 ]; then echo yes; else echo no; fi
