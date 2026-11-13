# Workflow risk scan

Whether one GitHub Actions workflow file stays inside the envelope a change may push in without the owner: forge-hosted runners, a read-only token, no secrets, no trigger with the base repository's rights (plan task T55, part 1; design: [Merge](../design/05-architecture/08-merge.md), [Threat model](../design/05-architecture/14-threat-model.md#ci-log-excerpts)). `Plugin/GitHubForge/Domain/WorkflowFileScan::scan($text)` (`#[NoDiscard]`) answers a list of `WorkflowFinding` (a `WorkflowRisk`, the line and a detail), empty when the file stays inside. `php bin/console intakt:forge:workflow-risk <file>` (`UseCase/ScanWorkflow/ScanWorkflowCommand`) runs it on a file read with Symfony's `Filesystem::readFile()`: exit 0 without findings, 1 with any, 2 when the file cannot be read. Nothing pushes or holds on the answer yet: reading the head's, main's and the merged workflows, and the files they run, are the later parts of T55.

## A strict line reader

`Domain/WorkflowLines` reads the text line by line into `WorkflowMapping`, `WorkflowSequence` and `WorkflowScalar` nodes (each with its line), and throws `WorkflowFormNotRead` (line and reason) for any form outside the subset it knows; the scan turns that into one `unreadable` finding. A YAML library is not used on purpose: it would accept forms (anchors and aliases, merge keys, tags, multi-line plain and quoted values, escapes) that let a runner, a permission or a secret look different to the scan than to GitHub, so the reader accepts only:

- block mappings with plain or simply quoted keys (`[A-Za-z0-9_][A-Za-z0-9_./-]*`), each key once per mapping, and block sequences, also a sequence under a key at the key's own indentation (`on:` then `- pull_request_target` keeps the items under `on`);
- one-line plain, single-quoted and double-quoted values (no backslash in a double-quoted one), and one-line flow values (`[a, b]`, `{a: b}`) whose items are plain or quoted scalars without brackets, braces or `#`;
- block scalars with the headers `|`, `>` and a chomping sign (`|-`, `>+`), whose extent is every following line indented at least as deep as the first text line, blank lines included. An indentation number, leading blank lines deeper than the text, or a later line deeper than the key but less deep than the first text line make the extent uncertain, and the file `unreadable`;
- comments, blank lines, a leading byte order mark and one leading `---`.

Everything else is `unreadable`: a tab in the indentation, a value that goes on over the next line, a line that belongs to no key or item above it, a value that starts with `&`, `*`, `!`, `@`, `` ` ``, `%`, `|`/`>` with text after the header, a nested key inside a value (`a: b: c`), text that is not UTF-8, and control characters or the YAML 1.1 line breaks (U+0085, U+2028, U+2029), since another reader would split those lines differently.

## What the scan reports

| Kind | Rule |
|---|---|
| `trigger` | each event under `on` (a name, a list or a mapping's keys) that is not a `Domain/ContainedTrigger`: `push`, `pull_request`, `workflow_dispatch`, `schedule`, `merge_group` |
| `runner` | each `runs-on` label that is not a `Domain/HostedRunnerLabel` (GitHub's standard hosted labels); a `runs-on` holding `${{` (a variable, a matrix value or any expression, even while the variable is unset); a `runs-on` mapping (`group`, `labels`) |
| `permissions` | a scope set to `write` or `write-all` (`Domain/TokenPermissions`), in the workflow's or a job's `permissions`; a job with no `permissions` of its own while the workflow has none, since the repository default may write |
| `secret` | every match of `secrets` as a word in any key or value, in any case, except `secrets.GITHUB_TOKEN` (`Domain/SecretReferences`); a block scalar is checked over its whole text, so `if: \|` or `run: >-` with `secrets.KEY` in a later line is a finding at the key's line; `secrets: inherit` is one too |
| `unreadable` | a form the reader refuses (above); no `on` or no `jobs` mapping; a job that is not a mapping, has no `runs-on`, or calls another workflow (`uses:`); an empty or non-scalar trigger or runner label; a permission level other than `read`, `none` or `write`, or permissions that are neither `read-all`, `write-all` nor a mapping |

Findings are sorted by line. The `secret` rule is a word match on purpose: a false finding only asks the owner, while a reference the scan missed would push a secret-reading workflow unasked.

## Limits

- One file at a time: the merge with main, the files a workflow runs (scripts, the CI image's Dockerfile, local actions) and a failed variable read are not part of this part.
- The hosted label list is fixed in code; a new GitHub label counts as `runner` until it is added.
- The scan cannot tell a self-hosted runner registered with a hosted label from GitHub's own runner.
