# Highlights: Symfony and Symfony AI in Intakt

Intakt runs on PHP 8.5, Symfony 8.2.0-BETA2, Symfony AI 1.0.0-BETA1 and the PHP MCP SDK. This page lists every Symfony, Symfony AI and FrankenPHP feature Intakt uses or plans to use, and what it found in the beta. A change that starts or stops using a feature adds a fragment for its row in the same change (see `AGENTS.md`).

This page is assembled by `bin/highlights` from `docs/highlights.d/`: `layout.md` holds this text, the headings and the table headers, and each change adds one fragment named `<issue>-<slug>.md` with its rows under `## <section>` headings. Fragments apply in the order they were merged (the order git added them on the first-parent history, an uncommitted fragment last). Fragments with no order between them (added by one commit or one merge, several uncommitted ones, or read outside git) stop the assembly when two of them set the same row and no later fragment sets it too, since nothing says which is newer: delete the superseded fragment. A row whose first cell matches an earlier row (for a list item, its text before the closing status parenthesis) replaces it in place. The page is not committed (git ignores it), so two changes never touch the same file: it is published from main (on input changes, daily, by hand) at <https://shinyredapples.github.io/intakt/highlights/>; run `bin/highlights` to read it locally, and never edit or commit it; the `highlights` gate of `bin/check` checks the assembly of the fragments and fails when the page is committed or a local copy differs from the assembly.

A fragment writes the status as `planned`, `in progress` (an open pull request) or `done` (on main); the published page settles these statuses from the recorded issue states by the rule stated at its top, and only when the recording supplies usable states: without them (a missing, empty or invalid state file) every status stays as written and the rule sentence is left out. **Where** names the plan task and its issue, and once built the main file. A row cites the issue, not the pull request: the issue links its pull requests, and a build cannot know its pull request number before the pull request exists.

## Symfony AI Platform

| Feature | How Intakt uses it | Status | Where |
|---|---|---|---|

## Symfony AI Agent

| Feature | How Intakt uses it | Status | Where |
|---|---|---|---|

## Symfony AI Store

| Feature | How Intakt uses it | Status | Where |
|---|---|---|---|

## AI Bundle

| Feature | How Intakt uses it | Status | Where |
|---|---|---|---|

## MCP Bundle and MCP SDK

| Feature | How Intakt uses it | Status | Where |
|---|---|---|---|

## Mate

| Feature | How Intakt uses it | Status | Where |
|---|---|---|---|

## Symfony components

| Feature | How Intakt uses it | Status | Where |
|---|---|---|---|

## FrankenPHP

| Feature | How Intakt uses it | Status | Where |
|---|---|---|---|

## Tooling

| Feature | How Intakt uses it | Status | Where |
|---|---|---|---|

## Found in the beta

Each entry is an isolated reproduction under `upstream/<slug>/` at pinned versions (PHPUnit tests or a `reproduce.php` script). The owner approves each report before it is filed. **Upstream issue** gives the decision from the folder's `REPORT.md` and where its text is: `ISSUE.md` (a new issue), `COMMENT.md` (a comment on an existing one) or a fix pull request.

| Probe | Status | Where | Upstream issue |
|---|---|---|---|

## PHP 8.5 in use
