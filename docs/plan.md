# Plan: the review loop (first release)

Status: approved by the owner on 2026-10-27. The tasks are issues #5 to #35, #60, #61, #63, #64 and #67. Spec: [spec.md](spec.md). Deadline: 2026-11-22 18:00 CET.

## Conventions

- Every task is one pull request, mergeable on main with its own tests, built and reviewed as the design describes ([Choosing work](design/05-architecture/03-choosing-work.md)).
- **Waits for** names the tasks that must be merged first (they become `Waits for #n` lines when the issues exist). Tasks without a dependency between them may run in parallel.
- **Size:** S up to about 150 changed lines, M up to about 400, L above that (an L gets a plan first; split it if the plan shows two outcomes).
- **Evidence** is what the pull request must show from a running install, besides green tests.
- **Labels** on each issue, from the design's [label families](design/05-architecture/04-labels.md): `intakt` on all; `class:` and `priority:` as listed; `plan:required` on L tasks; `model:strong` only where noted.
- **PHP 8.5** lines name the 8.5 feature that fits a task (see `AGENTS.md`); use it where it makes the code clearer.
- Contracts and config shapes are not designed ahead: each task adds the contract methods and config keys it uses, and nothing more ([Left to the builder](design/08-left-to-the-builder.md)). Every user-visible task updates `docs/user/` and `docs/architecture/`.
- A task that starts or stops using a Symfony or Symfony AI feature updates its row in [highlights.md](highlights.md) (status, task and issue, path; never the pull request number); reviewers check it.

## Milestones

| Milestone | Outcome | Tasks | Target |
|---|---|---|---|
| M1 Review core | one reviewer returns a schema-valid verdict for a local branch, recorded in the ledger; Intakt boots and runs probes offline | T1 to T7, T31, T36 | 2026-11-02 |
| M2 Panel and rounds | a configured panel, quorum, persistent finding ids, the judge, a console command | T8 to T14 | 2026-11-09 |
| M3 Agents and memory | MCP server and client, the Store briefing, labels, GitHub with webhooks, branch cleanup, a subscription reviewer | T15 to T19, T30, T32, T33 | 2026-11-14 |
| M4 Live dashboard | rounds, streaming progress, verdicts and cost, live; server metrics | T20, T21, T34 | 2026-11-17 |
| M5 Demo | image in worker mode with no state leaks, `docker compose up`, sample repository, the five-minute path, hardening, a development setup with file watching | T22 to T29, T35 | 2026-11-21 |

## M1 Review core

### T1 Verdict schema and its validation
- **Description:** The `Review` core module's Contract gets the verdict as typed PHP objects: backed enums for verdict, severity and `fixable_by`, nested lists of findings, `resolved` and `follow_ups`, and `evidence`. These classes are the verdict's source of truth. The Platform's structured output fills them (`asObject()`, a response format naming the class) and the Validator subscriber checks their constraints; output that does not map (invalid JSON, prose, a fenced block) or fails validation becomes a failed review that says why, not a `changes` verdict ([Reviews](design/05-architecture/07-reviews.md)). T1 shipped with such output yielding `changes`; the task that builds failed reviews changes that behavior. A JSON schema file matching the design stays as a test oracle that the classes must match.
- **Acceptance:** the design's example verdict maps to the objects; each field rule (enum, required, types) has a failing case; invalid JSON, prose and a fenced block all yield a failed review; a test fails when the classes and the schema file disagree.
- **Evidence:** a test run listing the cases; the verdict class and schema file paths.
- **PHP 8.5:** `clone()` with property overrides for the readonly value objects; `#[NoDiscard]` on the parse result.
- **Waits for:** none. **Size:** M. **Labels:** `class:core`, `priority:high`.

### T36 A PHPStan rule: stateful services implement ResetInterface
- **Description:** A PHPStan rule next to `ParameterLimitRule`: a service class with a property that is not `readonly` must implement `ResetInterface`, so Symfony resets it between requests in worker mode and between messages in consumers. Value objects, entities, DTOs, exceptions and tests are exempt.
- **Acceptance:** a mutable service fails; with `ResetInterface` it passes; a `final readonly` service, an entity and a value object pass; the rule runs in `bin/check` and main passes.
- **Evidence:** the rule's test run and `bin/check` on main.
- **Waits for:** none. **Size:** S. **Labels:** `class:tooling`, `priority:high`.

### T31 `bin/instance` and `bin/probe` for reviewers and the boot gate
- **Description:** `bin/instance` boots Intakt from a clean checkout after `composer install` with the plain Symfony runtime (PHP's built-in web server on a free local port, SQLite in a scratch directory), checks that it answers, prints one JSON status line (`ready` or `failed` with the reason), and stops cleanly. `bin/probe <file>` boots the same instance and runs a reviewer's small PHPUnit test or PHP script against it, with network access cut, and prints its output and exit code. Both work offline, so the build loop can run reviewer probes and a boot gate on Intakt itself. FrankenPHP is a binary, not a Composer package, so worker mode arrives with the image in T22, which switches `bin/instance` to it inside the image.
- **Acceptance:** both commands work with the network disabled; a broken kernel config makes `bin/instance` report `failed` with the error and a non-zero exit; a probe that reaches for the network fails; the instance leaves no process or file behind; both are documented in `docs/architecture/`.
- **Evidence:** the JSON status of a clean and of a broken boot, and a probe run with its output, all without network.
- **PHP 8.5:** `#[NoDiscard]` on the instance status.
- **Waits for:** none. **Size:** M. **Labels:** `class:tooling`, `priority:high`.

### T2 Ledger: append-only events on SQLite
- **Description:** The `Ledger` core module stores events (review started, reviewer started, verdict received, judge done, usage recorded) in one append-only table through Doctrine, with a migration. No updates or deletes. Event ids are UUIDv7 (Symfony Uid), so they sort by time; findings use the same id type when they are stored.
- **Acceptance:** appending and reading events in order works; event ids are UUIDv7; an update or delete is refused; tests are in the `persistence` group.
- **Evidence:** `bin/console doctrine:migrations:migrate` output on SQLite and the events of a test run read back with a console query.
- **PHP 8.5:** `#[NoDiscard]` on the append result, so a write that failed cannot be ignored.
- **Waits for:** none. **Size:** M. **Labels:** `class:core`, `priority:high`.

### T3 Persistence tests on MySQL, MariaDB and PostgreSQL in CI
- **Description:** A CI job matrix with service containers runs the `persistence` group on each engine. Protected path: the owner approves the merge of the workflow change; it is pushed without a separate approval only when the workflow scan finds it not risky (see Merge).
- **Acceptance:** the matrix runs on the branch and is green on all three engines; the SQLite run in `bin/check` stays unchanged.
- **Evidence:** the CI run link with the three jobs.
- **Waits for:** T2. **Size:** S. **Labels:** `class:tooling`, `priority:normal`.

### T4 Providers, tiers and routing in config
- **Description:** AI Bundle configuration for at least two Platform providers (for example Anthropic and OpenAI) plus an OpenAI-compatible endpoint, keys from Symfony secrets or the environment. Intakt's own config maps capability tiers (`strong`, `standard`, `light`) to provider models per project through a model router and the AI Bundle's `model_catalog` config; several projects per install.
- **Acceptance:** the container builds with two projects that map tiers differently; a missing key disables that provider with a clear message instead of failing the boot; tests use `MockPlatformFactory` with `ScriptedResponse` (the in-memory platform no longer exists).
- **Evidence:** `bin/console debug:config ai` and an Intakt command that lists projects and their resolved tiers.
- **Waits for:** none. **Size:** M. **Labels:** `class:core`, `priority:high`.

### T5 Vendor contract and the Symfony AI Platform vendor
- **Description:** The `Vendor` plugin contract gets the one method the review needs (run a structured call for a tier). The first implementation, `Plugin/SymfonyAiVendor`, uses the Platform's structured output with the T1 verdict class (`asObject()`, checked by the Validator subscriber) and records token usage per call in the ledger (started, settled, unsettled). Per-call cost comes from the result's `getTokenUsages()` (`TokenUsage`, cached tokens included); the `remaining*` token figures a provider returns feed the budget planner.
- **Acceptance:** a structured call against `MockPlatformFactory` with a `ScriptedResponse` returns a typed verdict object; usage, cached tokens included, is recorded once per call; the `remaining*` figures reach the planner; a 429 is caught as `RateLimitExceededException` with its reset from `getRetryAfter()`, other transient errors through `RetryableExceptionInterface`, never parsed from text; the T1 schema file is the test oracle, not the call's source.
- **Evidence:** one real call per configured provider from a console command, with its usage line (keys from the owner's environment, never committed).
- **PHP 8.5:** `#[NoDiscard]` on usage reservations; the pipe operator for building the request from task, tier and verdict class.
- **Waits for:** T1, T2, T4. **Size:** M. **Labels:** `class:core`, `priority:high`.

### T6 Forge contract and the local git forge
- **Description:** The `Forge` contract gets what reading a change needs (head, base, diff, files at head). `Plugin/LocalGitForge` treats a branch of a local repository against its base as a pull request, through the Process component.
- **Acceptance:** the diff and file contents at the exact head are returned; a moved head is detected; tests use a temporary repository.
- **Evidence:** a console command printing the change summary of a sample branch.
- **PHP 8.5:** the pipe operator for turning `git diff` output into changed files.
- **Waits for:** none. **Size:** M. **Labels:** `class:core`, `priority:high`.

### T7 One reviewer agent with read-only repository tools
- **Description:** A reviewer is a Symfony AI Agent with tools to read a file, list a directory and search at the exact head (tool effect hints: read-only), a prompt per role and the task text. It returns the T1 verdict through the T5 vendor; the conversation is kept per round in an Agent message store (`MessageStoreInterface`, the agent's `message_store` option; the Doctrine DBAL store in production). The reviewer prompt includes the rubric of [How code reads](design/02-principles.md#how-code-reads): story readability, needless abstraction, duplication of knowledge and what-comments, each finding citing the rule it breaks.
- **Acceptance:** with `MockPlatformFactory` and `ScriptedResponse` scripted to call tools, the agent reads files at the head and returns a verdict; a tool call outside the repository is refused; a malformed tool call ends as a failed review (asked again once, then parked; no review cycle), not a crash; round two reads cached tokens (a test on the recorded usage). Tests assert through the AI Bundle's `AiAssertionsTrait` (`assertToolCalled`, `assertPlatformCallCount`). A sample change with a what-comment and a single-implementation interface gets findings that cite those rules.
- **Evidence:** a real review of a sample branch by one provider: the verdict JSON and the tool calls it made.
- **Symfony AI:** Anthropic prompt caching on; tools run in a `FaultTolerantToolbox`, so a failing tool answers the model with an error; a `ToolCallRequested` listener denies tool calls whose arguments escape the checkout.
- **Prompt safety:** this task brings the fencing helper of the [threat model](design/05-architecture/14-threat-model.md#third-party-text); the task text, the diff and tool results reach the prompt only through it.
- **Waits for:** T5, T6. **Size:** M. **Labels:** `class:core`, `priority:high`.

## M2 Panel and rounds

### T8 Review panel and quorum policy
- **Description:** A panel is configured per project: reviewers (role, tier, provider, prompt, budget), a quorum policy (all, majority, one strong plus one) and rules choosing a panel by change (size, paths, `review:full`).
- **Acceptance:** each policy has passing and failing cases; a panel of one, two and three reviewers works; the panel is chosen from config alone.
- **Evidence:** two configs producing two different panels for the same change, shown by a dry-run command.
- **PHP 8.5:** closures in constant expressions for the built-in quorum policies.
- **Waits for:** T4, T7. **Size:** M. **Labels:** `class:core`, `priority:high`.

### T9 Parallel reviewers through Messenger
- **Description:** A review round dispatches one message per reviewer; workers run them in parallel and the round completes when all verdicts or failures are in. State lives in the database, so a restarted worker resumes. This is the first task with message handlers, so it also installs Messenger with a command bus and a query bus, and adds the typed handler attributes with their first use: a small `Core/Platform` module's `Contract` gets a backed enum `Bus` and `AsCommandHandler` and `AsQueryHandler`, each extending Symfony's `AsMessageHandler`, fixing `bus: Bus::X->value` and passing `handles`, `method` and `priority` through. A PHPArkitect rule lets nothing in `src/` but that `Contract` depend on `AsMessageHandler`; first prove with a fixture that PHPArkitect counts attribute uses, and if it does not, a guard test parses `src/` for a direct `#[AsMessageHandler]` (aliases included) and names the attribute to use.
- **Acceptance:** a forced-order test proves the round completes correctly whichever reviewer finishes first; a killed worker's reviewer is retried once and then counts as `blocked`. A command handler is not callable on the query bus, and the reverse; the boundary rule (or guard test) fails on a fixture that uses `AsMessageHandler` directly, including through an alias; a handler that does not read its message declares it with `handles:`.
- **Evidence:** a round of three reviewers with their start and end times overlapping in the ledger.
- **Symfony:** the Workflow component is the state machine of the round and of each reviewer, with its Graphviz diagram in `docs/architecture/`. A rate-limited reviewer message is retried after the provider's reset: the exception implements `RecoverableExceptionInterface` and `getRetryDelay()` returns `getRetryAfter()`. A message that reaches the failure transport means the reviewer is `blocked`. A Semaphore per vendor account caps its parallel calls.
- **Waits for:** T8. **Size:** L. **Labels:** `class:core`, `priority:high`, `plan:required`.

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
- **Acceptance:** the judge's output is a typed object through structured output, checked by the Validator; an invalid judge answer means every blocking finding is `act`; the builder-facing list holds only `act` findings.
- **Evidence:** a real judge result on the T10 rounds.
- **Waits for:** T10. **Size:** M. **Labels:** `class:core`, `priority:high`.

### T13 `intakt:review` console command
- **Description:** One command reviews a change end to end (`--project`, change reference, `--round`) and prints the outcome, the act list and the cost, or JSON with `--format=json`.
- **Acceptance:** exit codes distinguish approve, changes and blocked; the JSON output validates against a documented schema.
- **Evidence:** the command's output on the sample repository with two providers.
- **Symfony:** Console 8.1 input mapping for the arguments and options; tests use `CommandTester` assertions and `AiAssertionsTrait`.
- **Waits for:** T12. **Size:** S. **Labels:** `class:feature`, `priority:high`.

### T14 Provider limits and substitutes
- **Description:** A rate-limited or failing provider puts its account in cooldown until the reset time; the reviewer moves to the next account of the same vendor and role by priority, and only when every such account is out does the panel substitute a reviewer from the same tier on another provider and record that a catch-up review is owed.
- **Acceptance:** cooldown is per account, shared by all reviewers on it; a same-vendor account is tried before another provider and owes no catch-up review; substitution follows config; an outage of every provider ends the round as `blocked` with the reason.
- **Evidence:** a round with one provider's key revoked, showing the substitute and the owed catch-up; and a round with one of two same-vendor accounts out, showing the move to the other account and no catch-up owed.
- **Symfony:** provider failover through `FailoverPlatform`, wrapped by Intakt's own class that records the switch and does not rely on it alone; per-account cooldowns as RateLimiter windows aligned to the vendor's reset; Scheduler ticks for usage probes and deadlines.
- **Waits for:** T9. **Size:** M. **Labels:** `class:core`, `priority:normal`.

## M3 Agents and memory

### T15 MCP server for agents
- **Description:** The MCP Bundle exposes tools to start a review, read its status, read verdicts and the act list, and list projects, plus resources for a round's findings. Read tools are marked read-only.
- **Acceptance:** an MCP client test (PHP MCP SDK client) calls every tool; starting a review needs the configured token; errors are MCP errors, not stack traces.
- **Evidence:** an agent CLI connected over MCP starting a review of the sample branch and reading the act list (transcript excerpt).
- **PHP 8.5:** closures in attributes where an MCP tool's input needs a check.
- **Tool safety:** every tool carries the MCP SDK's annotations (`readOnlyHint`, `destructiveHint`); whether a tool may write is decided by a Symfony Security voter on Intakt's own config, never by the model's arguments. A contract test checks that a tool without parameters encodes them as `"properties": {}` and that the protocol version is negotiated correctly with Claude Code; a failure is an upstream report under `upstream/`.
- **MCP:** this is the owner's MCP Bundle server (T16 adds the per-review one), with `session.lock` on. Tool authorization goes through `#[IsGrantedTool]` backed by Intakt's voter; long tools send progress notifications.
- **Worker:** the MCP server runs in its own named FrankenPHP worker (see T22), so streamed MCP responses can't starve the dashboard's worker threads.
- **Waits for:** T13. **Size:** M. **Labels:** `class:feature`, `priority:high`.

### T16 Reviewer tools over MCP
- **Description:** The read-only repository tools of T7 are served by an Intakt MCP Bundle server per review, and reviewers reach them through the agent's built-in MCP client tool (`ai.agent.<name>.tools` with an `mcp_server` entry), not hand-written client code, so any MCP-capable reviewer can use the same tools.
- **Acceptance:** a review with tools over MCP gives the same verdict path as the in-process tools in tests; the server serves only the head under review.
- **Evidence:** a real review whose tool calls appear in the MCP server's log.
- **Tool safety:** every tool carries the MCP SDK's annotations (`readOnlyHint`, `destructiveHint`); whether a tool may write is decided by a Symfony Security voter on Intakt's own config, never by the model's arguments. A contract test checks that a tool without parameters encodes them as `"properties": {}` and that the protocol version is negotiated correctly with Claude Code; a failure is an upstream report under `upstream/`.
- **MCP:** the per-review server runs with `session.lock` on; tool authorization goes through `#[IsGrantedTool]` backed by Intakt's voter; long tools send progress notifications.
- **Waits for:** T7, T15. **Size:** M. **Labels:** `class:core`, `priority:normal`.

### T17 Project memory with the Store
- **Description:** After each completed review, resolved findings and their causes are indexed with the Symfony AI Store (an embedded store that works on SQLite); each reviewer's briefing retrieves the entries relevant to the files under review, with a size cap. Indexing uses the `DocumentIndexer`, retrieval the `Retriever`, and the briefing reaches the agent through `MemoryInputProcessor`. A reranker is added only when a configured provider supports reranking.
- **Acceptance:** indexing and retrieval are tested with an in-memory store; the briefing respects its cap; memory is read-only to reviewers.
- **Evidence:** a second review on the sample repository whose briefing cites an earlier finding.
- **Store backend:** `symfony/ai-sqlite-store` (SQLite FTS5 plus vector similarity computed in PHP, needs only `pdo_sqlite`) behind Intakt's own small retrieval contract; the in-memory store in tests. It loads all vectors into PHP memory for a query, which is fine at the size of one project's memory; `docs/architecture/` says so.
- **Waits for:** T12. **Size:** M. **Labels:** `class:feature`, `priority:normal`.

### T18 GitHub forge plugin
- **Description:** `Plugin/GitHubForge` reads a pull request (head, base, diff, files at head) with a token from the environment and posts the outcome as one review comment carrying the round's one-time token. It calls GitHub through a scoped HttpClient wrapped in `RetryableHttpClient`. Before a review body and its findings are posted, the values of every credential mounted or injected into that job are redacted and the secret scan runs over the text; a hit is not posted and is a failed review (asked again once, then parked; no review cycle) ([Threat model](design/05-architecture/14-threat-model.md)). Build bundles are not taken in by any task of this plan: the task that first takes in a build's hand-back carries the same step, so it is not lost when that task is planned.
- **Acceptance:** recorded HTTP responses drive the tests; a head that moved during review is detected; nothing is posted when the round is stale; a review text holding a mounted credential's value is posted redacted, and one that still trips the secret scan is not posted and is a failed review (asked again once, then parked; no review cycle).
- **Evidence:** a review of a real pull request in a test repository, with the posted comment.
- **PHP 8.5:** `Uri\Rfc3986\Uri` instead of `parse_url` for pull-request and API URLs.
- **Waits for:** T13. **Size:** M. **Labels:** `class:feature`, `priority:normal`.

### T30 Branch cleanup with archive refs
- **Description:** The cleanup rules of the design ([Merge](design/05-architecture/08-merge.md#branch-cleanup)) for the GitHub and local forges, limited to branches with Intakt's prefix (other people's branches only when the project opts in), never the default, protected or release branches: delete a merged branch at once; a closed unmerged one after 30 days unless its pull request is labeled `keep`; a branch without a pull request after 60 days (stale at 30) unless claimed by a person's commit or the project file's `keep-branches` list. A weekly Scheduler job warns a week ahead; every deleted head is kept as an archive ref in Intakt's own mirror and never pushed.
- **Acceptance:** each rule has a test against a temporary repository and recorded forge responses, with a clock the test controls; the default, protected and release branches and branches without Intakt's prefix are never deleted unless the project opts in; both kinds of claim keep a branch; a deleted branch is restored from its archive ref; nothing is deleted without the warning a week before.
- **Evidence:** the warning list and the archive refs after a cleanup run on the GitHub test repository.
- **PHP 8.5:** the pipe operator for the rule chain that picks the branches.
- **Waits for:** T18. **Size:** M. **Labels:** `class:feature`, `priority:low`.

### T19 Labels on reviewed changes
- **Description:** On forges with labels, `review:full` picks the full panel and `model:<tier>` sets the start tier; Intakt writes one `status:` label (`in-review`, `waiting-on-you`) to mirror the round. Steering labels count only from accounts with write access.
- **Acceptance:** each label's effect is tested; a status label changed by a person is restored with one comment; two values in one family resolve to the last applied.
- **Evidence:** the GitHub test pull request with its labels before and after a round.
- **PHP 8.5:** `Uri\Rfc3986\Uri` for webhook URLs; `#[NoDiscard]` on minted round tokens.
- **Waits for:** T18. **Size:** M. **Labels:** `class:feature`, `priority:low`.

### T32 GitHub webhooks through Webhook and RemoteEvent
- **Description:** A GitHub `RequestParser` for the Webhook component verifies the delivery's signature and turns pull request, review and check events into RemoteEvents that the loop consumes, so Intakt reacts to events instead of polling.
- **Acceptance:** recorded deliveries drive the tests; a valid delivery yields the matching RemoteEvent for a pushed head, a submitted review and a completed check; a missing or wrong signature is refused before any parsing; an unknown event type is acknowledged and ignored.
- **Evidence:** a delivery from the GitHub test repository and the round it started, from the webhook log and the ledger.
- **PHP 8.5:** `#[NoDiscard]` on the signature check result.
- **Waits for:** T18. **Size:** M. **Labels:** `class:feature`, `priority:normal`.

### T33 A subscription reviewer through the Claude Code or Codex platform bridge
- **Description:** One reviewer role runs through `symfony/ai-claude-code-platform` or `symfony/ai-codex-platform` (BETA1) on a subscription account (its automation token where the vendor has one), behind the same vendor contract as the API providers. Its stream parsing is exercised on purpose, including a stream that ends early (`IncompleteStreamException`). The package is a new dependency the owner approves.
- **Acceptance:** recorded streams drive the tests; a complete stream yields a typed verdict; a truncated stream raises `IncompleteStreamException` and the reviewer ends as `blocked` with the reason, its usage counted once; the setup shows which plans the vendor's terms allow for this use.
- **Evidence:** a real review of the sample branch by the subscription reviewer, with its verdict and usage line.
- **Waits for:** T7. **Size:** M. **Labels:** `class:feature`, `priority:normal`.

## M4 Live dashboard

### T20 Dashboard of reviews
- **Description:** A small server-rendered dashboard (Twig) lists projects and reviews; a review page shows each round, its reviewers, verdicts, findings with ids, the judge's call, tokens and cost. A button starts a review of a listed change.
- **Acceptance:** pages render from the ledger alone; functional tests cover each page; no page polls.
- **Evidence:** screenshots of a completed two-round review.
- **Waits for:** T13. **Size:** M. **Labels:** `class:feature`, `priority:high`.

### T21 Live updates with streaming and Mercure
- **Description:** Reviewer progress and round state are published through Mercure as Turbo Streams as they happen, served by FrankenPHP's built-in Mercure hub; the review page updates without reloads. Progress comes from the agent's streaming `Progress` updates (tool calls, streamed text). A stop button on a running reviewer calls `Execution::cancel()`.
- **Mercure:** the built-in hub (FrankenPHP 1.13 and later) is configured with `publisher` and `issuer` keys, and symfony/mercure mints the tokens. Messenger consumers publish through the hub endpoint; the web side may use `mercure_publish()`, checked with `function_exists()` at runtime (it is absent in the CLI, so its existence is never cached at container build). The `bolt` transport keeps history, so a client that reconnects catches up with `Last-Event-ID`. The hub's route is excluded from `encode` in the Caddyfile.
- **Acceptance:** events published by a round reach a subscribed test client in order; a client that reconnects with `Last-Event-ID` receives the events it missed; the stop button cancels the reviewer's execution and the ledger records the stop; the page works, without live updates, when the hub is down.
- **Evidence:** a short screen recording or a sequence of screenshots of a live round.
- **Waits for:** T20. **Size:** M. **Labels:** `class:feature`, `priority:high`.

### T34 Server metrics on the dashboard
- **Description:** FrankenPHP's Prometheus metrics, the admin API's `/frankenphp/threads` and Mercure's `mercure_health` feed an infrastructure row on the dashboard (worker threads busy and idle, queue depth, worker restarts, hub health) and the container's health check. The admin API stays bound to localhost; the app reads it from inside the container.
- **Acceptance:** recorded metrics and threads responses drive the tests; the row renders from them and shows a clear state when an endpoint is down; the health check fails when the hub reports unhealthy or no worker thread is ready; no admin endpoint is reachable from outside the container.
- **Evidence:** a screenshot of the infrastructure row during a live round, and the health check output from the container.
- **Waits for:** T20. **Size:** S. **Labels:** `class:feature`, `priority:normal`.

## M5 Demo

### T22 Container image
- **Description:** A FrankenPHP-based image with Intakt, built on `dunglas/frankenphp` 1.13.4 or later (the PHP 8.5 ZTS variant), pinned by digest. 1.13.2 and 1.13.4 fix worker-mode state leaks between requests (`error_get_last`, per-request env in `$_SERVER`, a `putenv` use-after-free, `SplTempFileObject`, `http_get_last_response_headers`); 1.13.1 adds graceful shutdown without 500s and restart on crash with backoff. Built in CI and run locally; SQLite in a volume.
- **Caddyfile:** a named `app` worker runs the app in worker mode (Symfony Runtime's `FrankenPhpWorkerRunner`); a separate named `mcp` worker matches the MCP route with its own `num`, `max_threads` and `max_wait_time`, so streamed MCP responses can't starve the dashboard. The built-in Mercure hub as T21 configures it. The admin API is bound to localhost.
- **Worker restarts:** `FRANKENPHP_LOOP_MAX` is set (Symfony 8.1's worker loop, default 500), and a check at kernel terminate ends the loop cleanly when memory passes a ceiling. FrankenPHP's restart-on-crash defaults stay (`max_consecutive_failures`, default 6); the container has a Docker restart policy and a `HEALTHCHECK` on the metrics endpoint.
- **Consumers and the Scheduler:** separate long-running processes from the same image, run with plain `php`, not `frankenphp php-cli`, until the SIGTERM probe in T28 passes on 1.13.4.
- **Acceptance:** the image starts, migrates and serves the dashboard in worker mode, and `bin/instance` in the image boots in worker mode; a request to the MCP route is served by the `mcp` worker; a worker passing the memory ceiling ends its loop and is replaced without a failed request; the consumers and the Scheduler run beside it and restart on failure; the health check turns unhealthy when the metrics endpoint stops answering; the admin API is not reachable from outside the container; it runs as a non-root user; no secret is baked in.
- **Evidence:** `docker run` output, the image digest, and the dashboard served from the container.
- **Waits for:** T21. **Size:** M. **Labels:** `class:feature`, `priority:high`.

### T29 No state leaks in worker mode
- **Description:** A test boots one kernel and sends many requests through it, as FrankenPHP worker mode does: different users, flash messages, Doctrine reads and writes, and agent runs with their message store. Any service that keeps state between requests is made to implement `ResetInterface`, and the entity manager is reset. In worker mode `umask()` and `setlocale()` are process-wide and `$_ENV` is not reset between requests, so the test checks them too. It runs both with and without `FRANKENPHP_RESET_KERNEL=1` (a kernel cloned per request); the image ships without it, because it would hide the leaks this task looks for.
- **Acceptance:** no request sees the previous request's user, flash messages, entity manager identity map entries, agent messages, `umask()`, locale or `$_ENV` changes; the test passes with and without `FRANKENPHP_RESET_KERNEL=1`; the test fails when a reset is removed.
- **Evidence:** the test run, and the same check by hand against the image from T22 (two browsers, alternating requests).
- **PHP 8.5:** `#[NoDiscard]` on the reset results the test asserts.
- **Upstream probe:** T28's worker-mode probe (one kernel, two models, two MCP sessions, 1,000 calls) covers the Symfony AI side of this task.
- **Reset probe:** boot the kernel once and run the same request twice with different models, tools and conversations; nothing from the first may show in the second. The test also lists every Symfony AI service in the container that has mutable state but does not implement `ResetInterface` (the Platform `Provider` keeps its last resolved model in `$resolvedModel`); each confirmed leak becomes a reproduction under `upstream/` for the owner's approval.
- **Waits for:** T20, T22. **Size:** M. **Labels:** `class:core`, `priority:high`.

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
- **Acceptance:** Intakt degrades to a failed review (asked again once, then parked; no review cycle) with a recorded reason in every case; usage of an interrupted call is settled once; each reproduction runs alone against the pinned versions.
- **Evidence:** the list of cases with their outcomes, and any `upstream/` folders.
- **Upstream probes:** Each probe is an isolated PHPUnit test under `upstream/<slug>/` at pinned versions, driven by `MockPlatformFactory` with `ScriptedResponse` or a redacted `HttpCassette`; the owner approves each report before it is posted.
  1. Failover with errors that surface only when the result is read (a 429 in a `DeferredResult`): the second platform must answer. Two platforms of the same class must not share one rate-limit key.
  2. Streaming with a tool call and a stream cut mid-text: the execution fails, usage is counted once, the tool runs once.
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
- **Acceptance:** every case ends as a valid verdict or a failed review (asked again once, then parked; no review cycle) with a recorded reason, never a crash; oversized input is cut with a note the reviewer sees; each reproduction runs alone.
- **Evidence:** the list of cases with their outcomes, and any `upstream/` folders.
- **Upstream probes:** Each probe is an isolated PHPUnit test under `upstream/<slug>/` at pinned versions, driven by `MockPlatformFactory` with `ScriptedResponse` or a redacted `HttpCassette`; the owner approves each report before it is posted.
  1. Worker-mode state: one kernel, alternating requests with two models and two MCP sessions; no model or tool list crosses over, and memory stays flat over 1,000 calls (see T29).
  2. Structured output with enums and nested lists per provider, and a stream truncated mid-array: a typed exception, never a half-filled object.
  3. MCP: three parallel `tools/call` on one session over Streamable HTTP, with and without `session.lock`; each response and progress token arrives once. Repeat with the client pinned to protocol 2025-11-25. Empty parameters encode as `{}`.
  4. FrankenPHP: `messenger:consume` under `frankenphp php-cli` on 1.13.4 receives SIGTERM and stops cleanly, with the message in flight finished or requeued. If it still crashes, a Symfony reproduction under `upstream/` for the existing upstream issue (php/frankenphp#2479); the owner approves before it is posted.
- **PHP 8.5:** the pipe operator for the trimming steps.
- **Waits for:** T7, T16. **Size:** M. **Labels:** `class:core`, `priority:normal`, `model:strong`.

### T35 Development setup with file watching
- **Description:** A development compose override that runs the image with FrankenPHP's `watch` on the worker, so a changed PHP, Twig or config file restarts the workers, with hot reload in the browser. Nothing of it ships in the production image or the default `compose.yaml`.
- **Acceptance:** with the override, a changed controller or template is served on the next request without a manual restart; without it, the image runs as T22 builds it; `docs/architecture/` says how to start it.
- **Evidence:** the override's start output and one edit served without a restart.
- **Waits for:** T22. **Size:** S. **Labels:** `class:tooling`, `priority:low`.

## Parallel paths

- From the start: T1, T2, T4, T6, T31, T26, T27.
- After M1: T8 to T14 mostly in order; T11 and T14 in parallel after T9.
- T15 to T19 and T20 to T21 in parallel after T13; T30 and T32 after T18; T33 after T7; T29 after T20 and T22; T34 after T20; T35 after T22.
- The demo tasks (T22 to T24) are the critical path to the deadline; T25 and T28 run alongside them.
