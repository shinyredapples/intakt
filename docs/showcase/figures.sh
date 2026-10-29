#!/usr/bin/env bash
# Prints the showcase figures as a markdown table.
# Read-only: it only lists pull requests and comments through the GitHub API.
# Needs gh (logged in with read access) and jq.
#
#   docs/showcase/figures.sh [owner/repo]
set -euo pipefail

repo="${1:-shinyredapples/intakt}"
owner="${OWNER:-haraldpdl}"

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

gh pr list -R "$repo" --state merged --limit 1000 \
    --json number,author,mergedBy,createdAt,mergedAt,labels,closingIssuesReferences,reviews >"$tmp/prs.json"
gh api "repos/$repo/issues/comments?per_page=100" --paginate --jq '.[]' | jq -s '.' >"$tmp/comments.json"

jq -rn --slurpfile prs "$tmp/prs.json" --slurpfile comments "$tmp/comments.json" --arg owner "$owner" '
  # The loop runs as the GitHub App intakt-runner; gh shows it as app/intakt-runner,
  # the REST API as intakt-runner[bot].
  def is_loop: . == "app/intakt-runner" or . == "intakt-runner[bot]" or . == "intakt-runner";
  def median: sort | if length == 0 then null
      elif length % 2 == 1 then .[(length - 1) / 2]
      else (.[length / 2 - 1] + .[length / 2]) / 2 end;
  def pct(a; b): if b == 0 then "n/a" else "\((a * 100 / b) | round)%" end;
  def round1: (. * 10 | round) / 10;
  def findings: [.body | split("\n")[] | select(test("^\\s*(\\d+\\.|-) \\[P[1-3]\\]"))
      | capture("\\[(?<p>P[1-3])\\]").p];

  ($prs[0] | map(select(.mergedBy.login // "" | is_loop))) as $loop
  | ($loop | map(.number)) as $nums
  | ($comments[0] | map(. + {pr: (.issue_url | split("/") | last | tonumber)})
      | map(select(.pr as $n | $nums | index($n)))) as $own
  | ($own | map(select(.user.login | is_loop)
      | select(.body | test("^\\*\\*Review by (Claude|GPT)\\*\\*"))
      | . + {who: (.body | capture("^\\*\\*Review by (?<w>Claude|GPT)").w), f: findings,
             probe: (.body | test("```probe")),
             ran: (.body | test("(^|\n)Probes: (the )?probes? (ran|passed|confirmed|reproduced)"; "i"))})) as $reviews
  | ($reviews | map(.f[])) as $all
  | ($reviews | map(select(.probe) | .f[])) as $probed
  | ($reviews | map(select(.ran) | .f[])) as $ran
  | ($loop | map(.number as $n | $reviews | map(select(.pr == $n))
      | [map(select(.who == "Claude")) | length, map(select(.who == "GPT")) | length] | max)) as $rounds
  | ($loop | map(((.mergedAt | fromdateiso8601) - (.createdAt | fromdateiso8601)) / 3600)) as $hours
  | ($own | map(select(.user.login == $owner and (.body | test("^approve [0-9a-f]{7,40}\\b"))) | .pr)) as $approved
  | ($loop | map(select(.number as $n | (.reviews | map(select(.author.login == $owner and .state == "APPROVED")) | length > 0)
      or ($approved | index($n))))) as $owner_ok
  | ($loop | map(select((.labels | map(.name) | index("owner-approval")) or (.number as $n | $approved | index($n))))) as $needed
  | "| Figure | Value |",
    "| --- | --- |",
    "| Pull requests merged by the loop (`intakt-runner[bot]` as merger) | \($loop | length) |",
    "| ... of those also opened by the loop | \($loop | map(select(.author.login | is_loop)) | length) |",
    "| Issues closed by those merges | \($loop | map(.closingIssuesReferences[].number) | unique | length) |",
    "| Review comments (Claude / GPT) | \($reviews | length) (\($reviews | map(select(.who == "Claude")) | length) / \($reviews | map(select(.who == "GPT")) | length)) |",
    "| Review rounds per merged pull request (median / max) | \($rounds | median) / \($rounds | max) |",
    "| Findings (P1 / P2 / P3) | \($all | length) (\($all | map(select(. == "P1")) | length) / \($all | map(select(. == "P2")) | length) / \($all | map(select(. == "P3")) | length)) |",
    "| Review comments that report a probe run (a \"Probes:\" line saying it ran) | \($reviews | map(select(.ran)) | length) of \($reviews | length) |",
    "| Findings in a review that reports a probe run | \($ran | length) of \($all | length) (\(pct($ran | length; $all | length))) |",
    "| Findings in a review that carried probe evidence (a fenced `probe` block) | \($probed | length) of \($all | length) (\(pct($probed | length; $all | length))) |",
    "| Time from opening to merge (median) | \($hours | median | round1) hours |",
    "| Merges flagged for the owner'"'"'s approval (`owner-approval` label: protected paths) | \($needed | length) of \($loop | length) |",
    "| ... of those with an owner approval on record (a review or an `approve <sha>` comment) | \($needed | map(.number) as $m | $owner_ok | map(select(.number as $n | $m | index($n))) | length) |",
    "| Merges the owner approved without the flag | \($needed | map(.number) as $m | $owner_ok | map(select(.number as $n | $m | index($n) | not)) | length) |"
'
