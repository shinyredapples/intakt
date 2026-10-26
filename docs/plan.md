# Plan: the review loop (first release)

Status: draft for the owner's approval. The tasks become issues only after he approves this plan. Spec: [spec.md](spec.md). Deadline: 2026-11-22 18:00 CET.

## Conventions

- Every task is one pull request, mergeable on main with its own tests, built and reviewed as the design describes ([Work intake](design/05-architecture/03-work-intake.md)).
- **Waits for** names the tasks that must be merged first (they become `Waits for #n` lines when the issues exist). Tasks without a dependency between them may run in parallel.
- **Size:** S up to about 150 changed lines, M up to about 400, L above that (an L gets a plan first; split it if the plan shows two outcomes).
- **Evidence** is what the pull request must show from a running install, besides green tests.
- **Labels** on each issue, from the design's [label families](design/05-architecture/04-labels.md): `intakt` on all; `class:` and `priority:` as listed; `plan:required` on L tasks; `model:strong` only where noted.
- **PHP 8.5** lines name the 8.5 feature that fits a task (see `AGENTS.md`); use it where it makes the code clearer.
- Contracts and config shapes are not designed ahead: each task adds the contract methods and config keys it uses, and nothing more ([Left to the builder](design/08-left-to-the-builder.md)). Every user-visible task updates `docs/user/` and `docs/architecture/`.

## Milestones

| Milestone | Outcome | Tasks | Target |
|---|---|---|---|
| M1 Review core | one reviewer returns a schema-valid verdict for a local branch, recorded in the ledger; Intakt boots and runs probes offline | T1 to T7, T31 | 2026-11-02 |
| M2 Panel and rounds | a configured panel, quorum, persistent finding ids, the judge, a console command | T8 to T14 | 2026-11-09 |
| M3 Agents and memory | MCP server and client, the Store briefing, labels, GitHub, branch cleanup | T15 to T19, T30 | 2026-11-14 |
| M4 Live dashboard | rounds, streaming progress, verdicts and cost, live, with no state leaks in worker mode | T20, T21, T29 | 2026-11-17 |
| M5 Demo | image, `docker compose up`, sample repository, the five-minute path, hardening | T22 to T28 | 2026-11-21 |

## M1 Review core

### T1 Verdict schema and its validation
- **Description:** The `Review` core module's Contract gets the verdict as value objects (verdict, finding, severity, `fixable_by`, `evidence`) and a JSON schema matching the design. A parser turns raw model output into a verdict; anything invalid becomes a `changes` verdict with a finding that says why.
- **Acceptance:** the design's example verdict parses; each field rule (enum, required, types) has a failing case; invalid JSON, prose and a fenced block all yield `changes`.
- **Evidence:** a test run listing the cases; the schema file path.
- **PHP 8.5:** `clone()` with property overrides for the readonly value objects; `#[\NoDiscard]` on the parse result.
- **Waits for:** none. **Size:** M. **Labels:** `class:core`, `priority:high`.

### T31 `bin/instance` and `bin/probe` for reviewers and the boot gate
- **Description:** `bin/instance` boots Intakt from a clean checkout after `composer install` (FrankenPHP worker mode, SQLite in a scratch directory), prints one JSON status line (`ready` or `failed` with the reason), and stops cleanly. `bin/probe <file>` boots the same instance and runs a reviewer's small PHPUnit test or PHP script against it, with network access cut, and prints its output and exit code. Both work offline, so the build loop can run reviewer probes and a boot gate on Intakt itself.
- **Acceptance:** both commands work with the network disabled; a broken kernel config makes `bin/instance` report `failed` with the error and a non-zero exit; a probe that reaches for the network fails; the instance leaves no process or file behind; both are documented in `docs/architecture/`.
- **Evidence:** the JSON status of a clean and of a broken boot, and a probe run with its output, all without network.
- **PHP 8.5:** `#[\NoDiscard]` on the instance status.
- **Waits for:** none. **Size:** M. **Labels:** `class:tooling`, `priority:high`.

### T2 Ledger: append-only events on SQLite
- **Description:** The `Ledger` core module stores events (review started, reviewer started, verdict received, judge done, usage recorded) in one append-only table through Doctrine, with a migration. No updates or deletes.
- **Acceptance:** appending and reading events in order works; an update or delete is refused; tests are in the `persistence` group.
- **Evidence:** `bin/console doctrine:migrations:migrate` output on SQLite and the events of a test run read back with a console query.
- **PHP 8.5:** `#[\NoDiscard]` on the append result, so a write that failed cannot be ignored.
- **Waits for:** none. **Size:** M. **Labels:** `class:core`, `priority:high`.

### T3 Persistence tests on MySQL, MariaDB and PostgreSQL in CI
- **Description:** A CI job matrix with service containers runs the `persistence` group on each engine. Protected path: the owner approves the workflow change before it is pushed.
- **Acceptance:** the matrix runs on the branch and is green on all three engines; the SQLite run in `bin/check` stays unchanged.
- **Evidence:** the CI run link with the three jobs.
- **Waits for:** T2. **Size:** S. **Labels:** `class:tooling`, `priority:normal`.

### T4 Providers, tiers and routing in config
- **Description:** AI Bundle configuration for at least two Platform providers (for example Anthropic and OpenAI) plus an OpenAI-compatible endpoint, keys from the environment. Intakt's own config maps capability tiers (`strong`, `standard`, `light`) to provider models per project; several projects per install.
- **Acceptance:** the container builds with two projects that map tiers differently; a missing key disables that provider with a clear message instead of failing the boot; tests use the in-memory platform.
- **Evidence:** `bin/console debug:config ai` and an Intakt command that lists projects and their resolved tiers.
- **Waits for:** none. **Size:** M. **Labels:** `class:core`, `priority:high`.

### T5 Vendor contract and the Symfony AI Platform vendor
- **Description:** The `Vendor` plugin contract gets the one method the review needs (run a structured call for a tier). The first implementation, `Plugin/SymfonyAiVendor`, uses the Platform's structured output with the T1 schema and records token usage per call in the ledger (started, settled, unsettled).
- **Acceptance:** a structured call against the in-memory platform returns a verdict; usage is recorded once per call; a 429 or quota error is a typed error with the reset time when the provider sends one, never parsed from model text; the structured-output call uses the T1 schema file, the same one validation uses.
- **Evidence:** one real call per configured provider from a console command, with its usage line (keys from the owner's environment, never committed).
- **PHP 8.5:** `#[\NoDiscard]` on usage reservations; the pipe operator for building the request from task, tier and schema.
- **Waits for:** T1, T2, T4. **Size:** M. **Labels:** `class:core`, `priority:high`.

### T6 Forge contract and the local git forge
- **Description:** The `Forge` contract gets what reading a change needs (head, base, diff, files at head). `Plugin/LocalGitForge` treats a branch of a local repository against its base as a pull request, through the Process component.
- **Acceptance:** the diff and file contents at the exact head are returned; a moved head is detected; tests use a temporary repository.
- **Evidence:** a console command printing the change summary of a sample branch.
- **PHP 8.5:** the pipe operator for turning `git diff` output into changed files.
- **Waits for:** none. **Size:** M. **Labels:** `class:core`, `priority:high`.

### T7 One reviewer agent with read-only repository tools
- **Description:** A reviewer is a Symfony AI Agent with tools to read a file, list a directory and search at the exact head (tool effect hints: read-only), a prompt per role and the task text. It returns the T1 verdict through the T5 vendor; the conversation is kept in a message store per round.
- **Acceptance:** with the in-memory platform scripted to call tools, the agent reads files at the head and returns a verdict; a tool call outside the repository is refused; a malformed tool call ends as `changes`, not a crash.
- **Evidence:** a real review of a sample branch by one provider: the verdict JSON and the tool calls it made.
- **Waits for:** T5, T6. **Size:** M. **Labels:** `class:core`, `priority:high`.

## M2 Panel and rounds

### T8 Review panel and quorum policy
- **Description:** A panel is configured per project: reviewers (role, tier, provider, prompt, budget), a quorum policy (all, majority, one strong plus one) and rules choosing a panel by change (size, paths, `review:full`).
- **Acceptance:** each policy has passing and failing cases; a panel of one, two and three reviewers works; the panel is chosen from config alone.
- **Evidence:** two configs producing two different panels for the same change, shown by a dry-run command.
- **PHP 8.5:** closures in constant expressions for the built-in quorum policies.
- **Waits for:** T4, T7. **Size:** M. **Labels:** `class:core`, `priority:high`.

### T9 Parallel reviewers through Messenger
- **Description:** A review round dispatches one message per reviewer; workers run them in parallel and the round completes when all verdicts or failures are in. State lives in the database, so a restarted worker resumes.
- **Acceptance:** a forced-order test proves the round completes correctly whichever reviewer finishes first; a killed worker's reviewer is retried once and then counts as `blocked`.
- **Evidence:** a round of three reviewers with their start and end times overlapping in the ledger.
- **Waits for:** T8. **Size:** M. **Labels:** `class:core`, `priority:high`.

### T10 Persistent finding ids across rounds
- **Description:** Round two and later pass the open findings to each reviewer; verdicts list `resolved` ids and mark `repeat`. A new P1 or P2 from round two on that is neither a regression nor cites a requirement is lowered to P3.
- **Acceptance:** ids stay stable over three rounds; resolved findings close; the goalpost rule is enforced in code, not only in the prompt.
- **Evidence:** two real rounds on a sample branch, the second after a fixing commit, with the finding table of both.
- **PHP 8.5:** `array_first()` and `array_last()` for the first and latest round of a finding.
- **Waits for:** T9. **Size:** M. **Labels:** `class:core`, `priority:high`.

### T11 Verdict identity
- **Description:** Each verdict records head, base, task revision, check command and panel policy. A change to any of them makes it stale; a clean merge of the base or a docs-only change keeps it for the untouched parts.
- **Acceptance:** each identity field invalidates on change; the two exceptions are covered.
- **Evidence:** a stale verdict shown as stale after a new commit on the sample branch.
- **Waits for:** T9. **Size:** S. **Labels:** `class:core`, `priority:normal`.

### T12 Judge step
- **Description:** A judge agent on a light tier marks each finding act, defer or reject against the task's acceptance criteria and non-goals, using structured output. Deferred findings become follow-ups.
- **Acceptance:** the judge's output is schema-checked; an invalid judge answer means every blocking finding is `act`; the builder-facing list holds only `act` findings.
- **Evidence:** a real judge result on the T10 rounds.
- **Waits for:** T10. **Size:** M. **Labels:** `class:core`, `priority:high`.

### T13 `intakt:review` console command
- **Description:** One command reviews a change end to end (`--project`, change reference, `--round`) and prints the outcome, the act list and the cost, or JSON with `--format=json`.
- **Acceptance:** exit codes distinguish approve, changes and blocked; the JSON output validates against a documented schema.
- **Evidence:** the command's output on the sample repository with two providers.
- **Waits for:** T12. **Size:** S. **Labels:** `class:feature`, `priority:high`.

### T14 Provider limits and substitutes
- **Description:** A rate-limited or failing provider puts its account in cooldown until the reset time; the panel substitutes a reviewer from the same tier on another provider and records that a catch-up review is owed.
- **Acceptance:** cooldown is per account, shared by all reviewers on it; substitution follows config; an outage of every provider ends the round as `blocked` with the reason.
- **Evidence:** a round with one provider's key revoked, showing the substitute and the owed catch-up.
- **Waits for:** T9. **Size:** M. **Labels:** `class:core`, `priority:normal`.

## M3 Agents and memory

### T15 MCP server for agents
- **Description:** The MCP Bundle exposes tools to start a review, read its status, read verdicts and the act list, and list projects, plus resources for a round's findings. Read tools are marked read-only.
- **Acceptance:** an MCP client test (PHP MCP SDK client) calls every tool; starting a review needs the configured token; errors are MCP errors, not stack traces.
- **Evidence:** an agent CLI connected over MCP starting a review of the sample branch and reading the act list (transcript excerpt).
- **PHP 8.5:** closures in attributes where an MCP tool's input needs a check.
- **Waits for:** T13. **Size:** M. **Labels:** `class:feature`, `priority:high`.

### T16 Reviewer tools over MCP
- **Description:** The read-only repository tools of T7 are served by an Intakt MCP server per review, and reviewers reach them through the PHP MCP SDK client, so any MCP-capable reviewer can use the same tools.
- **Acceptance:** a review with tools over MCP gives the same verdict path as the in-process tools in tests; the server serves only the head under review.
- **Evidence:** a real review whose tool calls appear in the MCP server's log.
- **Waits for:** T7, T15. **Size:** M. **Labels:** `class:core`, `priority:normal`.

### T17 Project memory with the Store
- **Description:** After each completed review, resolved findings and their causes are indexed with the Symfony AI Store (an embedded store that works on SQLite); each reviewer's briefing retrieves the entries relevant to the files under review, with a size cap.
- **Acceptance:** indexing and retrieval are tested with an in-memory store; the briefing respects its cap; memory is read-only to reviewers.
- **Evidence:** a second review on the sample repository whose briefing cites an earlier finding.
- **Waits for:** T12. **Size:** M. **Labels:** `class:feature`, `priority:normal`.

### T18 GitHub forge plugin
- **Description:** `Plugin/GitHubForge` reads a pull request (head, base, diff, files at head) with a token from the environment and posts the outcome as one review comment carrying the round's one-time token.
- **Acceptance:** recorded HTTP responses drive the tests; a head that moved during review is detected; nothing is posted when the round is stale.
- **Evidence:** a review of a real pull request in a test repository, with the posted comment.
- **PHP 8.5:** `Uri\Rfc3986\Uri` instead of `parse_url` for pull-request and API URLs.
- **Waits for:** T13. **Size:** M. **Labels:** `class:feature`, `priority:normal`.

### T30 Branch cleanup with archive refs
- **Description:** The cleanup rules of the design ([Merge](design/05-architecture/08-merge.md#branch-cleanup)) for the GitHub and local forges: delete a merged branch at once, a closed unmerged one after 30 days unless labeled `keep`, a branch without a pull request after 60 days (stale at 30); a weekly Scheduler job warns a week ahead; every deleted head is kept as an archive ref in Intakt's own mirror and never pushed.
- **Acceptance:** each rule has a test against a temporary repository and recorded forge responses, with a clock the test controls; a deleted branch is restored from its archive ref; nothing is deleted without the warning a week before.
- **Evidence:** the warning list and the archive refs after a cleanup run on the GitHub test repository.
- **PHP 8.5:** the pipe operator for the rule chain that picks the branches.
- **Waits for:** T18. **Size:** M. **Labels:** `class:feature`, `priority:low`.

### T19 Labels on reviewed changes
- **Description:** On forges with labels, `review:full` picks the full panel and `model:<tier>` sets the start tier; Intakt writes one `status:` label (`in-review`, `waiting-on-you`) to mirror the round. Steering labels count only from accounts with write access.
- **Acceptance:** each label's effect is tested; a status label changed by a person is restored with one comment; two values in one family resolve to the last applied.
- **Evidence:** the GitHub test pull request with its labels before and after a round.
- **PHP 8.5:** `Uri\Rfc3986\Uri` for webhook URLs; `#[\NoDiscard]` on minted round tokens.
- **Waits for:** T18. **Size:** M. **Labels:** `class:feature`, `priority:low`.

## M4 Live dashboard

### T20 Dashboard of reviews
- **Description:** A small server-rendered dashboard (Twig) lists projects and reviews; a review page shows each round, its reviewers, verdicts, findings with ids, the judge's call, tokens and cost. A button starts a review of a listed change.
- **Acceptance:** pages render from the ledger alone; functional tests cover each page; no page polls.
- **Evidence:** screenshots of a completed two-round review.
- **Waits for:** T13. **Size:** M. **Labels:** `class:feature`, `priority:high`.

### T21 Live updates with streaming and Mercure
- **Description:** Reviewer progress (tool calls, streamed text) and round state are published through Mercure as they happen, served by FrankenPHP's built-in hub; the review page updates without reloads.
- **Acceptance:** events published by a round reach a subscribed test client in order; the page works, without live updates, when the hub is down.
- **Evidence:** a short screen recording or a sequence of screenshots of a live round.
- **Waits for:** T20. **Size:** M. **Labels:** `class:feature`, `priority:high`.

### T29 No state leaks in worker mode
- **Description:** A test boots one kernel and sends many requests through it, as FrankenPHP worker mode does: different users, flash messages, Doctrine reads and writes, and agent runs with their message store. Any service that keeps state between requests is made to implement `ResetInterface`, and the entity manager is reset.
- **Acceptance:** no request sees the previous request's user, flash messages, entity manager identity map entries or agent messages; the test fails when a reset is removed.
- **Evidence:** the test run, and the same check by hand against the image from T22 (two browsers, alternating requests).
- **PHP 8.5:** `#[\NoDiscard]` on the reset results the test asserts.
- **Waits for:** T20. **Size:** M. **Labels:** `class:core`, `priority:high`.

## M5 Demo

### T22 Container image
- **Description:** A FrankenPHP-based image with Intakt: a Caddyfile that runs the app in FrankenPHP worker mode (Symfony Runtime's `FrankenPhpWorkerRunner`) with the Mercure hub, a worker restart after a set number of requests and on a memory ceiling, and the Messenger consumers and the Scheduler as separate long-running processes from the same image. Built in CI and run locally; SQLite in a volume.
- **Acceptance:** the image starts, migrates and serves the dashboard in worker mode; the consumers and the Scheduler run beside it and restart on failure; it runs as a non-root user; no secret is baked in.
- **Evidence:** `docker run` output and the dashboard served from the container.
- **Waits for:** T21. **Size:** M. **Labels:** `class:feature`, `priority:high`.

### T23 `docker compose up` with a sample repository
- **Description:** `compose.yaml` starts Intakt with a sample repository holding a base and a pull request branch with deliberate, findable issues. `.env.dist` lists the provider keys; the first start seeds the sample project and its panel.
- **Acceptance:** from a clean clone, `docker compose up` with one key reaches a reviewable sample change; with two keys the default panel uses two providers.
- **Evidence:** the timed run from `git clone` to the first verdict, recorded in the README.
- **Waits for:** T22. **Size:** M. **Labels:** `class:feature`, `priority:urgent`.

### T24 The five-minute path in the docs
- **Description:** The README's clone-to-demo path is made real and the "planned" marks removed for what works; `docs/user/` covers setup, the panel config, reading verdicts and connecting an MCP client; `docs/architecture/` covers the review flow with a Mermaid diagram.
- **Acceptance:** every command in the docs was run and shows its real output; no link is broken.
- **Evidence:** a fresh-machine run following only the README.
- **Waits for:** T23, T15. **Size:** S. **Labels:** `class:feature`, `priority:urgent`.

### T25 Provider outages and quotas on purpose
- **Description:** Scripted runs push the Platform and the Agent where providers fail: an outage mid-stream, parallel calls that hit a rate limit together, a quota exhausted during a round, a long run. Each likely library bug gets a reproduction in `upstream/`.
- **Acceptance:** Intakt degrades to `changes` or `blocked` with a recorded reason in every case; usage of an interrupted call is settled once; each reproduction runs alone against the pinned versions.
- **Evidence:** the list of cases with their outcomes, and any `upstream/` folders.
- **Waits for:** T14, T16. **Size:** M. **Labels:** `class:core`, `priority:normal`, `model:strong`.

### T26 Upstream reproduction runner
- **Description:** A console command runs every reproduction in `upstream/` and reports which still fail, so a new Symfony AI pre-release can be checked in one step.
- **Acceptance:** a passing and a failing sample reproduction are reported correctly; the gates still skip `upstream/`.
- **Evidence:** the command's output against the pinned versions.
- **Waits for:** none. **Size:** S. **Labels:** `class:tooling`, `priority:low`.

### T27 Mate for development diagnostics
- **Description:** Symfony AI Mate as a dev dependency, configured for the app, with `AGENTS.md` saying when to use it.
- **Acceptance:** `vendor/bin/mate tools:list` works on the dev install; nothing of it ships in the image.
- **Evidence:** the tool list and one tool call against the running dev app.
- **Waits for:** none. **Size:** S. **Labels:** `class:tooling`, `priority:low`.

### T28 Tool calls and context size on purpose
- **Description:** Tests and scripted runs push the Agent on its inputs: malformed and unknown tool calls, tool results too large for the context, an oversized diff, a task text that tries to steer the reviewer. Each likely library bug gets a reproduction in `upstream/`.
- **Acceptance:** every case ends as `changes` or `blocked` with a recorded reason, never a crash; oversized input is cut with a note the reviewer sees; each reproduction runs alone.
- **Evidence:** the list of cases with their outcomes, and any `upstream/` folders.
- **PHP 8.5:** the pipe operator for the trimming steps.
- **Waits for:** T7, T16. **Size:** M. **Labels:** `class:core`, `priority:normal`, `model:strong`.

## Parallel paths

- From the start: T1, T2, T4, T6, T31, T26, T27.
- After M1: T8 to T14 mostly in order; T11 and T14 in parallel after T9.
- T15 to T19 and T20, T21, T29 in parallel after T13; T30 after T18.
- The demo tasks (T22 to T24) are the critical path to the deadline; T25 and T28 run alongside them.
