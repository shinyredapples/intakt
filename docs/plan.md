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
- A task that starts or stops using a Symfony or Symfony AI feature updates its row in `docs/highlights.md` (status, task and issue, path; never the pull request number) through a new fragment in [`docs/highlights.d/`](highlights.d/), which `bin/highlights` assembles into the page (git ignores the page, so no change touches it); reviewers check it, and the `highlights` gate of `bin/check` checks the fragments, that the page is not committed, and on their assembly the status, the backticked paths, that no pull request number appears and that no row is still planned once every path it names exists; the `reproductions` gate checks that every folder under `upstream/` has a row in "Found in the beta".

## Milestones

| Milestone | Outcome | Tasks | Target |
|---|---|---|---|
| M1 Review core | one reviewer returns a schema-valid verdict for a local branch, recorded in the ledger; Intakt boots and runs probes offline | T1 to T7, T31, T36, T83 | 2026-11-02 |
| M2 Panel and rounds | a configured panel, quorum, persistent finding ids, the judge, a console command | T8 to T14 | 2026-11-09 |
| M3 Agents and memory | MCP server and client, the Store briefing, GitHub, a subscription reviewer | T15 to T18, T33 | 2026-11-14 |
| M4 Live dashboard | rounds, streaming progress, verdicts and cost, live | T20, T21 | 2026-11-17 |
| M5 Demo | image in worker mode with no state leaks, `docker compose up`, sample repository, the five-minute path, hardening | T22 to T29 | 2026-11-21 |
| After the hackathon | branch cleanup, labels, server metrics, a development setup with file watching, approving protected changes on the spot, every account working at once with a hand-off at a limit, and last the optional GitHub webhooks; the demo runs on a local repository without them | T19, T30, T34, T35, T75 to T80, T81, T32 (optional) | after the deadline |
| M6 Reviewer for the loop | see [Replacing the existing loop](#replacing-the-existing-loop) | T37, T38, T40, T43 to T46 | tasks 2026-11-25; weekly report after one week, 2026-12-03 |
| M7 Builder half | see [Replacing the existing loop](#replacing-the-existing-loop) | T39, T47 to T69 | 2026-12-15 (range 2026-12-08 to 2026-12-23) |
| M8 Switch | see [Replacing the existing loop](#replacing-the-existing-loop) | T70 to T74 | takeover 2027-01-08 (range 2026-12-22 to 2027-01-22) |

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

### T83 A shrink-only ratchet list for a new PHPStan rule
- **Description:** The gate support for [Ratchet lists](design/04-constraints.md#ratchet-lists-for-a-new-rule-decided-2026-11-06), built before the next PHPStan rule part that lands with a list. A new gate (its own class and tests, appended to the gate list of `bin/check`) reads the lists from the includes of `config/gates/phpstan.neon` that point outside `config/gates/`, compares each with the target branch's version (main for a pull request) and fails when an entry is added, a `count` rises, or an entry's identifier, message pattern or path changes; deleting entries and lowering counts pass. A list absent on the target branch passes only in the change that adds its include. A list names its cleanup issue in its header. PHPStan's `reportUnmatchedIgnoredErrors` stays on, so a stale entry fails the `phpstan` gate. `docs/guide/testing-a-new-check.md` drops its "planned" mark.
- **Acceptance:** an added entry, a raised count, a widened message pattern (`#.*#`) and a changed path each fail; a deleted entry and a lowered count each pass; a new list fails without its include in the same change and passes with it; a list without a cleanup issue in its header fails; a listed violation in a moved file fails; a violation not in the list and an entry that matches nothing each fail the `phpstan` gate; `bin/check` on main passes.
- **Evidence:** the gate's test run listing the cases; `bin/check` on a temporary branch that adds a list entry (fails) and on one that deletes one (passes).
- **Waits for:** none. **Size:** M. **Labels:** `class:tooling`, `priority:high`.

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
- **Description:** A CI job with service containers runs the `persistence` group on each engine (built as a matrix; since merged into one job with a service per engine, as each job costs an ephemeral runner a full reset; the engines run at once in one step, on tmpfs data directories). Protected path: the owner approves the merge of the workflow change; it is pushed without a separate approval only when the workflow scan finds it not risky (see Merge).
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
- **Symfony:** Console 8.1 input mapping for the arguments and options; tests use `CommandTester` assertions (the round is scripted at the vendor contract, so no platform call is made for `AiAssertionsTrait` to assert).
- **Waits for:** T12. **Size:** S. **Labels:** `class:feature`, `priority:high`.

### T14 Provider limits and substitutes
- **Description:** A rate-limited or failing provider puts its account in cooldown until the reset time; the reviewer moves to the next account of the same vendor and role by priority, and only when every such account is out does the panel substitute a reviewer from the same tier on another provider and record that a catch-up review is owed.
- **Acceptance:** cooldown is per account, shared by all reviewers on it; a same-vendor account is tried before another provider and owes no catch-up review; substitution follows config; an outage of every provider ends the round as `blocked` with the reason.
- **Evidence:** a round with one provider's key revoked, showing the substitute and the owed catch-up; and a round with one of two same-vendor accounts out, showing the move to the other account and no catch-up owed.
- **Symfony:** provider failover through `FailoverPlatform`, wrapped by Intakt's own class that records the switch and does not rely on it alone; per-account cooldowns as RateLimiter windows aligned to the vendor's reset; Scheduler ticks for usage probes and deadlines.
- **Catch-up:** the owed record only; the catch-up review itself is T66.
- **Waits for:** T9. **Size:** M. **Labels:** `class:core`, `priority:normal`.

## M3 Agents and memory

### T15 MCP server for agents
- **Description:** The MCP Bundle exposes tools to start a review, read its status, read verdicts and the act list, and list projects, plus resources for a round's findings. Read tools are marked read-only.
- **Acceptance:** an MCP client test (PHP MCP SDK client) calls every tool; starting a review needs the configured token; errors are MCP errors, not stack traces.
- **Evidence:** an agent CLI connected over MCP starting a review of the sample branch and reading the act list (transcript excerpt), with the tool calls scripted in the builder's sandbox, which has no model login; the same transcript with a logged-in model choosing the calls (owner provides).
- **PHP 8.5:** closures in attributes where an MCP tool's input needs a check.
- **Tool safety:** every tool carries the MCP SDK's annotations (`readOnlyHint`, `destructiveHint`); whether a tool may write is decided by a Symfony Security voter on Intakt's own config, never by the model's arguments. A contract test checks that a tool without parameters encodes them as `"properties": {}` and that the protocol version is negotiated correctly with Claude Code; a failure is an upstream report under `upstream/`.
- **MCP:** the owner's MCP Bundle server and, since T16 part 1, the per-review one, with `session.lock` on. Tool authorization goes through `#[IsGrantedTool]` backed by Intakt's voter; long tools send progress notifications.
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
- **Description:** After each completed review, resolved findings and their causes are indexed with the Symfony AI Store (an embedded store that works on SQLite); each reviewer's briefing retrieves the entries relevant to the files under review, with a size cap. Indexing uses the `DocumentIndexer`, retrieval the `Retriever`, and the briefing reaches the agent as a fenced user message of its own (not through `MemoryInputProcessor`, whose fixed preamble ranks memory above the system prompt). A reranker is added only when a configured provider supports reranking.
- **Acceptance:** indexing and retrieval are tested with an in-memory store; the briefing respects its cap; memory is read-only to reviewers.
- **Evidence:** a second review on the sample repository whose briefing cites an earlier finding.
- **Store backend:** `symfony/ai-sqlite-store` (SQLite FTS5 plus vector similarity computed in PHP, needs only `pdo_sqlite`) behind Intakt's own small retrieval contract; the in-memory store in tests. It loads all vectors into PHP memory for a query, which is fine at the size of one install's memory (one store for every project, its notes kept apart by project); `docs/architecture/` says so.
- **Waits for:** T12. **Size:** M. **Labels:** `class:feature`, `priority:normal`.

### T18 GitHub forge plugin
- **Description:** `Plugin/GitHubForge` reads a pull request (head, base, diff, files at head) with a token from the environment and posts the outcome as one review comment carrying the round's one-time token. It calls GitHub through a scoped HttpClient wrapped in `RetryableHttpClient`. Before a review body and its findings are posted, the values of every credential mounted or injected into that job are redacted and the secret scan runs over the text; a hit is not posted and is a failed review (asked again once, then parked; no review cycle) ([Threat model](design/05-architecture/14-threat-model.md)). Build bundles are not taken in by any task of this plan: the task that first takes in a build's hand-back carries the same step, so it is not lost when that task is planned.
- **Acceptance:** recorded HTTP responses drive the tests; a head that moved during review is detected; nothing is posted when the round is stale; a review text holding a mounted credential's value is posted redacted, and one that still trips the secret scan is not posted and is a failed review (asked again once, then parked; no review cycle).
- **Evidence:** a review of a real pull request in a test repository, with the posted comment.
- **PHP 8.5:** `Uri\Rfc3986\Uri` instead of `parse_url` for pull-request and API URLs.
- **Waits for:** T13. **Size:** M. **Labels:** `class:feature`, `priority:normal`.

### T33 A subscription reviewer through the Claude Code or Codex platform bridge
- **Description:** One reviewer role runs through `symfony/ai-claude-code-platform` or `symfony/ai-codex-platform` (BETA1) on a subscription account (its automation token where the vendor has one), behind the same vendor contract as the API providers. Its stream parsing is exercised on purpose, including a stream that ends early (`IncompleteStreamException`). The package is a new dependency the owner approves.
- **Acceptance:** recorded streams drive the tests; a complete stream yields a typed verdict; a truncated stream raises `IncompleteStreamException` and the reviewer ends as `blocked` with the reason, its usage counted once; the setup shows which plans the vendor's terms allow for this use.
- **Evidence:** a real review of the sample branch by the subscription reviewer, with its verdict and usage line.
- **Waits for:** T7. **Size:** M. **Labels:** `class:feature`, `priority:normal`.

## M4 Live dashboard

### T20 Dashboard of reviews
- **Description:** A small server-rendered dashboard (Twig) lists projects and reviews; a review page shows each round, its reviewers, verdicts, findings with ids, the judge's call, tokens and cost. A button starts a review of a listed change.
- **Acceptance:** pages render from the ledger alone (since #677, apart from the forge budget on `/reviews`, read from the forge's recorded readings without a forge call); functional tests cover each page; no page polls.
- **Evidence:** screenshots of a completed two-round review.
- **Waits for:** T13. **Size:** M. **Labels:** `class:feature`, `priority:high`.

### T21 Live updates with streaming and Mercure
- **Description:** Reviewer progress and round state are published through Mercure as Turbo Streams as they happen, served by FrankenPHP's built-in Mercure hub; the review page updates without reloads. Progress comes from the agent's streaming `Progress` updates (tool calls, streamed text). A stop button on a running reviewer calls `Execution::cancel()`.
- **Mercure:** the built-in hub (FrankenPHP 1.13 and later) is configured with `publisher` and `issuer` keys, and symfony/mercure mints the tokens. Messenger consumers publish through the hub endpoint; the web side may use `mercure_publish()`, checked with `function_exists()` at runtime (it is absent in the CLI, so its existence is never cached at container build). The `bolt` transport keeps history, so a client that reconnects catches up with `Last-Event-ID`. The hub's route is excluded from `encode` in the Caddyfile.
- **Acceptance:** events published by a round reach a subscribed test client in order; a client that reconnects with `Last-Event-ID` receives the events it missed; the stop button cancels the reviewer's execution and the ledger records the stop; the page works, without live updates, when the hub is down.
- **Evidence:** a short screen recording or a sequence of screenshots of a live round.
- **Waits for:** T20. **Size:** M. **Labels:** `class:feature`, `priority:high`.

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
  4. FrankenPHP: `messenger:consume` under `frankenphp php-cli` on 1.13.4 receives SIGTERM and stops cleanly, with the message in flight finished or requeued. If it still crashes, a Symfony reproduction under `upstream/` that records the existing upstream issue (php/frankenphp#2479); it adds nothing to that thread, so no comment is posted.
- **PHP 8.5:** the pipe operator for the trimming steps.
- **Waits for:** T7, T16. **Size:** M. **Labels:** `class:core`, `priority:normal`, `model:strong`.

## After the hackathon

The demo works on a local repository, so these tasks wait until after the deadline.

### T30 Branch cleanup with archive refs
- **Description:** The cleanup rules of the design ([Merge](design/05-architecture/08-merge.md#branch-cleanup)) for the GitHub and local forges, limited to branches with Intakt's prefix (other people's branches only when the project opts in), never the default, protected or release branches: delete a merged branch once the forge reports its pull request merged (kept, with the one comment of [Branch cleanup](design/05-architecture/08-merge.md#branch-cleanup), if no report comes within the short bound); a closed unmerged one after 30 days unless its pull request is labeled `keep`; a branch without a pull request after 60 days (stale at 30) unless claimed by a person's commit or the project file's `keep-branches` list. A weekly Scheduler job warns a week ahead; every deleted head is kept as an archive ref in Intakt's own mirror and never pushed.
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

### T34 Server metrics on the dashboard
- **Description:** FrankenPHP's Prometheus metrics, the admin API's `/frankenphp/threads` and Mercure's `mercure_health` feed an infrastructure row on the dashboard (worker threads busy and idle, queue depth, worker restarts, hub health) and the container's health check. The admin API stays bound to localhost; the app reads it from inside the container.
- **Acceptance:** recorded metrics and threads responses drive the tests; the row renders from them and shows a clear state when an endpoint is down; the health check fails when the hub reports unhealthy or no worker thread is ready; no admin endpoint is reachable from outside the container.
- **Evidence:** a screenshot of the infrastructure row during a live round, and the health check output from the container.
- **Waits for:** T20. **Size:** S. **Labels:** `class:feature`, `priority:normal`.

### T35 Development setup with file watching
- **Description:** A development compose override that runs the image with FrankenPHP's `watch` on the worker, so a changed PHP, Twig or config file restarts the workers, with hot reload in the browser. Nothing of it ships in the production image or the default `compose.yaml`.
- **Acceptance:** with the override, a changed controller or template is served on the next request without a manual restart; without it, the image runs as T22 builds it; `docs/architecture/` says how to start it.
- **Evidence:** the override's start output and one edit served without a restart.
- **Waits for:** T22. **Size:** S. **Labels:** `class:tooling`, `priority:low`.

T75 to T80 build [Approving protected changes](design/05-architecture/08-merge.md#approving-protected-changes). Until T80 switches a category on, every protected change still goes to the owner.

### T75 Hard floors and the approval decision in dry-run mode
- **Description:** The decision for a protected head in the design's order (floors, mechanical checks, security review, attacker test), each step a typed result. The hard floors are deterministic rules over the whole change: secrets and credentials handling, every workflow file, existing gate tests, the protected-path list, the approval rules, agent instructions, and a panel reviewer's loosening or missing class. Every protected head still goes to the owner; the computed decision is recorded in the ledger and the request names the first floor or check that failed. A replay command computes the decision for a repository's past approvals.
- **Acceptance:** each floor has a test that it sends a change to the owner and one that a neighboring change passes it; a request names its floor or check; no path approves by a timer, a veto window or a missing answer; a step that fails or cannot run sends the change to the owner; the replay reads recorded forge responses.
- **Evidence:** the replay's report over a temporary repository with recorded approvals; the replay over this repository's 32 approvals (owner provides).
- **PHP 8.5:** `#[NoDiscard]` on the decision.
- **Waits for:** T18. **Size:** M. **Labels:** `class:process`, `priority:normal`.

### T76 The `language` plugin kind and the comment-only check for PHP
- **Description:** A `language` plugin kind ([Plugins](design/05-architecture/02-plugins.md)) and its PHP implementation: two versions of a file compare equal when their `PhpToken` streams match without comments and whitespace; docblocks, suppression and ignore markers and inline allow directives count as code. A file of a language without a plugin is not covered.
- **Acceptance:** tests for an edited line comment (passes), an edited docblock type, an added ignore marker and a changed statement (each fails), a file that does not parse (fails), and a file of an unknown language (not covered, so the owner).
- **Evidence:** the check's result for a comment-only edit of `bin/probe` and for an edit that changes a statement, from a running install.
- **Waits for:** T75. **Size:** S. **Labels:** `class:process`, `priority:normal`.

### T77 Dependency policy over Composer, OSV.dev and deps.dev
- **Description:** A Composer adapter in the PHP `language` plugin turns the old and new manifest and lock file into neutral package changes; rules written once over them check allowed vendors, a clean OSV.dev audit of every new package including transitive ones, a minimum release age from deps.dev, and no change to scripts, plugins, repositories, Composer config, binaries or autoload files. The orchestrator makes the queries.
- **Acceptance:** recorded OSV.dev and deps.dev responses drive the tests; each rule has a passing and a failing case; a new transitive package with an advisory fails; an unreachable source or an unknown answer sends the change to the owner.
- **Evidence:** the policy's result for adding an official Symfony package and for a package from another vendor, against recorded responses; a run against the live sources (owner provides).
- **Waits for:** T76. **Size:** M. **Labels:** `class:process`, `priority:normal`.

### T78 Gate code no weaker, the violation corpus and the harness from main
- **Description:** A check job runs main's gate tests unchanged, and the violation corpus, against the head's gate code through the project's check command, in its own container and as its own user, with the result leaving through a channel the code under test cannot rewrite. Every violation a gate catches on a head becomes a corpus fixture. The boot gate runs main's `bin/instance` and `bin/probe` against the head, and main's isolation probes run through the head's harness.
- **Acceptance:** a new gate with new tests passes; an edited existing gate that lets a corpus fixture through fails; a head that rewrites the result file is still judged by the job's own channel; a harness change is booted by main's harness; a harness change that lets an isolation probe reach the network, a credential or a folder outside its own fails.
- **Evidence:** the check's output for an additive gate and for a weakened one on a temporary repository.
- **Waits for:** T75. **Size:** M. **Labels:** `class:process`, `priority:normal`.

### T79 Independent security review and the attacker test
- **Description:** Two reviewer roles in sessions of their own, apart from the panel: a security reviewer and an attacker. They read the diff, main's protected files and the check results, fenced, never the builder's pull request text. The attacker returns fixtures; plain code runs each against main's and the head's gate without network. Missing capacity sends the change to the owner; a second vendor is used when available. The threat model page lists both roles.
- **Acceptance:** with `MockPlatformFactory`, an agreeing review passes, a disagreeing or invalid one sends the change to the owner, the builder's text never reaches either prompt, a fixture only the head's gate accepts sends the change to the owner with the fixture, and no stand-in or substitute replaces either role.
- **Evidence:** a scripted attacker fixture that gets through a weakened gate, and the owner request it produces.
- **Waits for:** T7, T78. **Size:** M. **Labels:** `class:process`, `priority:normal`.

### T80 Canaries, earned autonomy and containment
- **Description:** Owner-known canary changes, each category with its own ([Merge](design/05-architecture/08-merge.md#earned-autonomy-per-category)), run through the decision on a schedule. Per category, the dashboard shows canary detection and agreement with the owner; the owner switches a category on in the owner's config once both meet the bar, and it falls back to dry-run mode on its own when a canary is missed or the owner overrules it. After an automatic approval merges, a failing canary or corpus fixture on main reverts it at once only when it is the one automatic approval merged since they last passed and no later merge touched its files; otherwise Intakt reverts nothing on its own, asks the owner naming every candidate merge, and gives no automatic approval in that project until the owner answers. A daily cap per project bounds automatic approvals, and each one is listed on the dashboard for a week. The code that applies the approval rules joins the protected paths, and the owner narrows `.github/CODEOWNERS` to the owner-only files.
- **Acceptance:** detection is counted per category over its own canaries; a category below 100% detection, or with fewer canaries than the minimum, cannot be switched on; a missed canary switches it off; a failing fixture on main after exactly one automatic approval, with no later merge on its files, produces a revert of exactly that merge; two candidate approvals, or a later merge on the same files, produce an owner request naming the candidates and no revert; the cap holds under two concurrent approvals (forced order); the dashboard line names the head, the category and its checks.
- **Evidence:** a canary run and an automatic revert on a temporary repository; the narrowed `.github/CODEOWNERS` (owner provides).
- **PHP 8.5:** the pipe operator for the canary run's steps.
- **Waits for:** T20, T77, T79. **Size:** L. **Labels:** `class:process`, `priority:normal`, `plan:required`.

### T81 Every account works at once, and a limit never loses work
- **Description:** The account model of the design ([Budget and model planner](design/05-architecture/06-budget-and-model-planner.md#order-of-use)), built on T40's per-account readings, reset times and ceilings (T40 stays the measured-use task; this one adds the pool, the ranking and the hand-off, and gives the Codex reading a second source besides its token counts). Every configured account of every vendor is in one pool for the roles the owner allows, unless the owner excludes it or a model; at each job start the planner ranks the allowed accounts by the room that resets soonest, then by the cheapest tier that fits; the number of jobs that start follows the total room, capped by the host, the CI runners and the review capacity. A job that hits a vendor limit stops cleanly: its commits are pushed to its work branch, its conversation stays in the message store (`symfony/ai-doctrine-message-store`), a hand-off note goes to the shared project memory, and the next ranked account continues at once through `MemoryInputProcessor`. A build may continue on another vendor within its role's tiers (the model change is recorded on the task); a review starts again on another account of the same vendor and is never continued by another vendor. The Codex vendor plugin reads a subscription account's usage from the endpoint Codex itself uses, best-effort, to feed the ranking.
- **Acceptance:** a hand-off at a limit loses no commit (every commit of the stopped job is on the work branch) and the next account continues from the note and the kept conversation without waiting for the first account's reset; two jobs run at the same time on two accounts, and a third waits when the pool has no room or a cap is reached; a window that resets in an hour with room ranks above one that resets in four days, an account at its ceiling ranks nowhere however soon it resets, and a paid account never ranks above a subscription with room; a build continued on another vendor records the model change; a review cut off by a limit before its answer starts streaming starts again on an account of its own vendor and never on another vendor's, and with none left the stand-in and catch-up rules apply; the hand-off note reaches the next prompt fenced; an excluded account or model is never ranked; a failed usage read (an HTTP error, a changed shape or an expired token) falls back to the limit signals from real calls, the plugin records when the endpoint stops answering, and the read is repeated while an account is out until a stated reset. Races (two starts claiming the last room) are proven by forced order.
- **Evidence:** a scripted run with `MockPlatformFactory` where the first account hits a limit mid-build and the second continues, with the ledger and the work branch's commits before and after; the ranking's output for three accounts with different reset times.
- **PHP 8.5:** `#[NoDiscard]` on the ranking result.
- **Waits for:** T14, T17, T40. **Size:** L. **Labels:** `class:feature`, `priority:normal`.

### T32 GitHub webhooks through Webhook and RemoteEvent (optional, last)
- **Description:** Optional, and only for installs that already have a public endpoint: polling stays the default and Intakt works fully without webhooks ([Merge](design/05-architecture/08-merge.md#waiting-for-ci)). A GitHub `RequestParser` for the Webhook component verifies the delivery's signature and turns pull request, review and check events into RemoteEvents that the loop consumes, so a change is seen sooner than the next poll.
- **Acceptance:** recorded deliveries drive the tests; a valid delivery yields the matching RemoteEvent for a pushed head, a submitted review and a completed check; a missing or wrong signature is refused before any parsing; an unknown event type is acknowledged and ignored; with no endpoint configured, polling alone behaves as before.
- **Evidence:** a delivery from the GitHub test repository and the round it started, from the webhook log and the ledger.
- **PHP 8.5:** `#[NoDiscard]` on the signature check result.
- **Waits for:** T18. **Size:** M. **Labels:** `class:feature`, `priority:normal`.

## Parallel paths

- From the start: T1, T2, T4, T6, T31, T26, T27; T83 before the next PHPStan rule part that lands with a ratchet list.
- After M1: T8 to T14 mostly in order; T11 and T14 in parallel after T9.
- T15 to T18 and T20 to T21 in parallel after T13; T33 after T7; T29 after T20 and T22.
- The demo tasks (T22 to T24) are the critical path to the deadline; T25 and T28 run alongside them.
- After the hackathon: T19 and T30 after T18; T34 after T20; T35 after T22; T75 after T18, then T76 and T78 in parallel, T77 after T76, T79 after T78, and T80 after T77 and T79; T81 after T14, T17 and T40; the optional T32 last, after T18.

## Replacing the existing loop

Status: approved by the owner on 2026-10-31. The tasks are issues #319 to #356, without the eligibility label until 2026-11-23. Goal: replace the existing loop for the owner's own use (GitHub only, Claude Code and Codex subscriptions, PHP only, the owner's two projects); other forges, languages and vendors come after the switch.

Conventions, in addition to the ones above:

- **The existing loop is a reference, not a source.** Its tests and docs show which behaviors and edge cases matter (bugs hit, races, security fixes). Every task here is an Intakt feature shaped by the design; its **Reference** line names the loop behaviors and test files whose cases become its acceptance cases, rewritten as Intakt tests. Nothing is translated module by module, and nothing that only worked around the loop's own constraints is carried over (see **Not in the switch** below).
- **Built by human-steered sessions**, reviewed by the existing loop. A task may be larger than a loop issue but stays one mergeable pull request with its own tests.
- **Deliberate differences** from the loop are listed in `docs/architecture/differences-from-the-existing-loop.md` (created by T44, extended by each task that adds one): typed verdicts instead of parsed review text, job containers instead of system users and sandboxes, database state and Messenger instead of state files and timer locks, the judge instead of end-of-cycle triage, polling with conditional requests before webhooks.
- **Decided constraints:** an approval is decided by the orchestrator on the spot or goes to the owner, never by a timed default; no decision needs a second AI vendor to be available (a missing vendor sends the decision to the owner); webhooks come last and stay optional; every mechanical check is a plugin per language; merges are signed fast-forwards of main; nothing public without the owner.
- **Owner decisions (2026-10-31):**
  1. Intakt runs on the owner's host in its own container with nesting, Docker inside and fenced from the local network like the CI runners; not a virtual machine.
  2. While both run, Intakt opens its pull requests under its own GitHub App identity; the existing loop leaves every pull request that identity opened and handles only its own.
  3. New GitHub Apps serve Intakt while both run; at takeover Intakt switches to the loop's Apps and the new ones are removed.
  4. The two watchdogs trust each other's signed merges by a peer signing key in each one's settings, with no push notice per merge.
  5. Intakt reviews in place of the loop's Claude reviewer, so it adds no capacity use of its own to hold back; the stage ends after at least seven days and 40 compared heads with fewer than 5% failed reviews and at most one head in ten where the loop raised a P1 the builder then fixed and Intakt approved.
  6. A project at weekly share 0 is paused (no builds, no reviews); the switch needs no work on it.
  7. Design decision 9 changes to signed fast-forwards of main by a non-force ref update (T62).
  8. The owner's dashboard reads Intakt's status JSON (T68) until Intakt's own dashboard replaces it.
  9. The dependency policy is the ecosystem adapter of the language plugin (Composer for PHP), not a new plugin kind (T52).
- Targets assume about three merged tasks a day from 2026-11-23, an L counting as two, and that M1 to M5 are merged by 2026-11-22 (T9 to T14, T18 and T33 are prerequisites here).

| Milestone | Outcome | Tasks | Target |
|---|---|---|---|
| M6 Reviewer for the loop | Intakt reviews every new green head of the owner's two projects in place of the loop's Claude reviewer, its verdict counts, and a weekly report compares its reviews with the loop's | T37, T38, T40, T43 to T46 | tasks 2026-11-25; weekly report after one week, 2026-12-03 |
| M7 Builder half | Intakt sizes, builds, checks, pushes, reviews through CI, asks the owner and merges, from its own job containers, on one project in a test repository | T39, T47 to T69, T82 | 2026-12-15 (range 2026-12-08 to 2026-12-23) |
| M8 Switch | Intakt's verdicts count, then it builds a labeled share, then takes over once every parity item has an Intakt test | T70 to T74 | takeover 2027-01-08 (range 2026-12-22 to 2027-01-22) |

## M6 Reviewer for the loop

T44 to T46 run in M6 alongside T37, T38, T40 and T43 (owner decision 2026-11-09, to start the builder chain early); their sections are under M7 Builder half.

### T37 GitHub App identity
- **Description:** `Plugin/GitHubForge` authenticates as Intakt's own GitHub App: a short-lived installation token per forge call and per git fetch or push, minted from the App's private key; a failed exchange stops the call and never falls back to another credential. At start the installation is read back and its repositories and permissions are compared with the configured ones. Trust checks (write access through the permission API, bot accounts never steer except Intakt's own identity and its maintainer's) use this identity.
- **Reference:** the existing loop's App authentication: `test_app_auth.py` (token minting and caching, no fallback, bot trust rules; the personal-token cases are not carried over).
- **Acceptance:** recorded responses drive the tests; an expired token is minted again; a failed exchange fails the call; a permission mismatch refuses the boot with the difference named; the private key never appears in logs or the ledger.
- **Evidence:** `(owner provides)` a token minted for the test repository and the installation read back.
- **PHP 8.5:** `#[NoDiscard]` on the minted token.
- **Waits for:** T18. **Size:** M. **Labels:** `class:core`, `priority:high`.

### T38 Pull request watcher by polling
- **Description:** A Scheduler tick lists each project's open pull requests with conditional requests (an unchanged list costs no rate limit), skips a pull request whose head and update time did not change, reads the CI state of the exact head (workflow runs and check suites), and starts a review round when CI on a new head has finished green. A head whose CI runs is looked at again later and holds nothing; red CI is recorded, not reviewed. Webhooks (T32) can later replace the tick; the tick stays as the fallback.
- **Reference:** `test_decision.py` (pending CI waits, red CI before any reviewer), `test_lanes_cap2.py` (heads with finished CI first, a running head keeps its place).
- **Acceptance:** an unchanged pull request causes no further reads; a new head with pending CI starts no round and is read again after the interval; a green head starts exactly one round; a head that moves during the round makes its verdicts stale (T11).
- **Evidence:** the ledger of a tick over two recorded pull requests, one pending and one green.
- **Waits for:** T37, T9. **Size:** M. **Labels:** `class:core`, `priority:high`.

### T40 Measured use and the start ceiling
- **Description:** `Core/Budget` keeps per account the measured use of each window (Claude's five-hour and weekly windows from its rate-limit events, Codex's from its token counts; T81 later adds a best-effort read of Codex's own usage endpoint) with the reading's age and the reset time. A call starts only while the measured use is below the window's ceiling (five-hour default 90%, the weekly one paced over the week); a second parallel start needs a fresher reading and a margin; without a trusted reading only one call runs at a time, and after a long outage the owner is told once. A structured usage-limit answer puts the account in cooldown until its reset; a shared back-off still lets approved heads merge. Ceilings are owner config.
- **Reference:** `test_limits.py`, `test_guard.py` (back-off, pause and drain blocks, merge-only refusal), `test_admission.py`, `test_claude_account.py` (back-off per account, a crash back-off blocks all), `test_budget_174.py` (probe outage notice once, measured use decides, never the estimate).
- **Acceptance:** each rule has a test with a controlled clock; an estimate never refuses a start that measured use allows; a reading is never applied to another account; the owner notice for an outage is sent once.
- **Evidence:** the guard's decisions over a scripted day of readings, printed by a console command.
- **PHP 8.5:** the pipe operator for the ceiling chain.
- **Waits for:** T14. **Size:** L. **Labels:** `class:core`, `priority:high`, `plan:required`.

### Reviewer for the loop (issue #1251)
- **Description:** Intakt does real review work before the builder half exists. A project mode `reviewer-for-loop` (D4): for every head the existing loop pushes on a pull request of the project, Intakt runs its Claude panel, and its review takes the place of the loop's Claude reviewer and counts for the merge, while the loop's GPT reviewer stays a required second vote (D1). Intakt never merges, pushes, labels or approves protected heads in this stage; the loop keeps building, merging and asking the owner (D2). Measured use and the start ceiling (T40) and the forge budget work stay as they are; no extra ceiling applies, because Intakt replaces a reviewer instead of adding one (D5).
- **Parts:** 1 (#1318, built): the mode, a round for each loop head without any extra ceiling, verdicts marked `reviewer-for-loop` that count, and the project mode guard that lets none of the project's writes reach the forge. 2 (#1319, built): once a round of such a project is judged, or its judging failed for good (published as `error`), Intakt publishes its verdict on that exact head under its App identity: the commit status `intakt/review` (`success` for approve, `failure` for changes, `error` when it could not review) and, for approve and changes, one pull request comment headed `**Review by Intakt** (head <12-hex>)` with the act list as `[P1|P2|P3] file:line: text` lines and a final fenced JSON block `{"verdict": "approve"|"changes", "head": "<40-hex>", "summary": "..."}`, followed only by an invisible marker of the round so a retried publication posts no second comment (only a comment of Intakt's own App counts, and one worker at a time publishes a round); this is the whole contract with the loop. The status and the comment tell the verdict the loop reads: an approval whose act list holds a P1 is published as changes, and an approval lists its other findings under a `Follow-ups:` line. 3 (#1434): the comparison-only report code, its ledger event kind and the dashboard's handling of it are removed, no mode other than `live` and `reviewer-for-loop` remains, and the module that holds the mode and the weekly report is named `ReviewerForLoop`; a verdict recorded under the removed mode reads as an ordinary one.
- **Rollback:** the loop switches back to its own Claude reviewer, and the project's mode goes back to `live`.
- **Decisions of part 3 (#1434):** D5: #325 (the owner's install, T43) is kept, now in this mode; #321 (the Codex reviewer, T39) moves to M7, and the loop's GPT reviewer is the second vote until the takeover. D6: stage 2 (#353) and the takeover (#356) build on this stage; this stage replaces the earlier comparison-only tasks (the former T41 and T42) in this plan.
- **Acceptance (part 1):** tests cover the mode selection and that a round runs for a loop head.
- **Evidence (part 1):** on a running install on SQLite, the mode set and a round started for a head.
- **Acceptance (part 2):** a test publishes a verdict for a head and asserts the status, the comment heading, the finding lines and the JSON block; the design, the plan, the user docs and the architecture docs describe that contract and the rollback.
- **Evidence (part 2):** from a running install on SQLite, the published status and comment against a scripted forge double; the first loop pull request merged on Intakt's and the loop's GPT approvals (owner provides).

### T43 The owner's install in reviewer-for-loop mode
- **Description:** The image runs in its own container with nesting on the owner's host (Docker inside, fenced from the local network) with both projects in `reviewer-for-loop` mode, the Claude automation tokens, and Home Assistant as the notifier for owner actions only. `docs/user/` gets the setup page. The loop's GPT reviewer is the second vote until the takeover (D5); the Codex reviewer (T39) is built in M7.
- **Acceptance:** the install answers, both projects list their panels, a tick runs without error.
- **Evidence:** `(owner provides)` the first day's weekly report.
- **Waits for:** the reviewer for the loop (above). **Size:** S. **Labels:** `class:tooling`, `priority:high`.

**Exit (owner's go):** at least seven days and 40 compared heads in the weekly report, fewer than 5% failed reviews, and no more than one in ten heads where the loop raised a P1 the builder then fixed and Intakt approved.

## M7 Builder half

T44 to T46 run in M6 (owner decision 2026-11-09, to start the builder chain early). T39 moved here under D5 of #1434: the loop's GPT reviewer is the second vote until the takeover. M7 holds T39 and T47 to T69, and their sections stay here.

### T39 A Codex subscription reviewer
- **Description:** A reviewer role runs through the Codex platform bridge on a subscription login: the login file is kept per account in the orchestrator, refreshed there, and given to the call read-only; the model and effort are pinned in config. The vendor's structured errors (usage limit, model not available for the plan, login lost) become an account cooldown or an account error with one owner notice, never a failed review or `changes`; the measured use it reports is recorded per account. The package is a new dependency the owner approves.
- **Reference:** `test_reviewer_accounts.py` (only a closed set of codes is an account error; a reviewer's own text never is), `test_gpt_probes.py` (the usage line), `test_agents.py` (any other reviewer failure is a failed review).
- **Acceptance:** recorded streams drive the tests; a complete stream yields a typed verdict; each structured error maps to its outcome; text that only mentions a limit is a failed review; usage is counted once.
- **Evidence:** a real review of the sample branch with its usage line `(owner provides)`.
- **Waits for:** T33, T14. **Size:** M. **Labels:** `class:feature`, `priority:high`.

### T44 Job containers through the Docker Engine API
- **Description:** A new `Core/Job` module starts each job in a fresh container: read-only root, a tmpfs work area, inputs mounted read-only, one output directory read after exit, memory and CPU limits, no forge credential or signing key, and an egress allowlist through a per-job proxy (vendor API and package registries only; `none` for check and probe jobs). Each job checks at start that what it was told it can do works (a probe file written and read back, the vendor reachable through the proxy) and runs in a reduced mode that says so otherwise. Creates `docs/architecture/differences-from-the-existing-loop.md`.
- **Reference:** `test_build_slot_ops.py` (a wiped home and own caches per session), `test_step_memory.py` (one runaway killed alone, the cause recorded), `test_review_gpt_egress.py` (deny by default, the login unreadable by commands the model runs).
- **Acceptance:** a job cannot reach a host outside its allowlist, read another job's output, or write outside its output directory; a memory kill is recorded as the job's cause; the start check reports a broken mount; containers are removed after exit, also after an orchestrator crash.
- **Evidence:** a job's start check and an egress refusal from its log.
- **Waits for:** none. **Size:** L. **Labels:** `class:core`, `priority:high`, `plan:required`, `model:strong`.

### T45 Job image for a PHP project
- **Description:** The job image of a project comes from its Dockerfile or devcontainer, or is generated: PHP from `composer.json` (`config.platform`, `require.php`), Composer, the project's extensions, and the Claude Code and Codex CLIs at pinned versions. A job never falls back to another PHP.
- **Reference:** `test_entry_harness.py` (a project without its PHP record gets no probes rather than the system's PHP).
- **Acceptance:** both of the owner's projects get an image whose `php -v` matches their constraint; a missing extension fails the image build with its name.
- **Evidence:** the image build output for the sample repository.
- **Waits for:** T44. **Size:** M. **Labels:** `class:core`, `priority:high`.

### T46 Bare mirror, build clone and the Source line
- **Description:** The orchestrator keeps a bare mirror per project; a build job clones main and its branch only. A `Source: <branch>@<sha>` line from a trusted author adds that one commit as a read-only ref, after the orchestrator verified the commit is on that branch. Read-only network git calls are retried after 10 and 30 seconds.
- **Reference:** `test_source.py` (prose is not a source line, an unsafe branch is an error, the clone resolves the ref at the verified SHA only), `test_network_git.py`.
- **Acceptance:** each case above; an untrusted source line is ignored with one comment.
- **Evidence:** a build clone's refs for a task with a source line.
- **Waits for:** T44, T37. **Size:** M. **Labels:** `class:core`, `priority:high`.

### T47 Work intake from GitHub issues
- **Description:** `Plugin/WorkSource` for GitHub issues: eligible when open, carrying the project's eligibility label and not `idea`, written and last edited by an account with write access (checked when it becomes eligible and before each build), and not blocked. `Waits for #n` holds a task until every named issue is closed, except a line naming the task's own pull request (or the pull request of its branch), which is no wait (the build hands over, see T59); a target that is a pull request counts as the task that pull request closes (its branch or closing keyword), closed when that task is; a task that failed for good holds its dependents with the reason. The state of every waited-for issue comes from the tick's one listing (or the stored state of an unchanged item, read conditionally), never one read per reference; only a complete, successful listing can mark a waited-for issue closed, and after a partial or failed listing every wait keeps its stored state. A worker that will not take work this tick reads nothing from the forge. Order: open pull requests first (only `urgent` before them), then priority, milestone due date and number. Whenever a task is queued (also after a hand-over is settled or a hold is released), its open pull request is read from the forge by the task's branch, and a task that has one is queued as a rework of that pull request, never as a fresh build. Queue state lives in the database; status labels mirror it (T19).
- **Reference:** `test_github.py` (idea issues skipped), `test_unblock.py` (trusted notes only, every referenced issue closed), `test_priority.py`, `test_queue.py`, `test_projects.py` (share order; a project at share 0 is paused for builds and reviews).
- **Acceptance:** each rule above has a case on recorded responses; an edit by an untrusted account makes the task ineligible until a trusted account confirms it; a forced-order test settles a hand-over on a task with an open pull request after its queue record is gone, and the task is queued as a rework of that pull request; a wait on several issues makes no per-reference read, a listing that comes back short releases no wait, and a worker that will not take work this tick makes no forge call.
- **Evidence:** the queue order printed for the GitHub test repository.
- **Waits for:** T19, T37. **Size:** M. **Labels:** `class:core`, `priority:high`.

### T48 Readiness, sizing and the split that happens once
- **Description:** One tool-less call per new task checks readiness (an observable result, an acceptance condition, one outcome; otherwise parked with one comment) and estimates its size against the size contract. An oversize task is split into two to four parts: the plan is recorded before any part exists, each part carries a part marker and its original's labels, a crash resumes from the record and finds a part whose create answer was lost, and the original stays as an umbrella that closes when every part is closed. A task is never split twice.
- **Reference:** `test_sizing.py` (parts in order with `Waits for` lines, never split again, tokens charged to the task), `test_lanes.py` (a split interrupted anywhere ends on the first part once), `test_unblock.py` (umbrella close).
- **Acceptance:** each case above; the call's output is a typed object through structured output, and an invalid one parks nothing and splits nothing.
- **Evidence:** a split of a sample task on the test repository and its record.
- **Waits for:** T47. **Size:** L. **Labels:** `class:core`, `priority:high`, `plan:required`.

### T49 Build job with Claude Code
- **Description:** A build job runs the Claude Code CLI in the job image with the task fenced, the decisions, the act list and CI excerpt of a rework, and the published pull request text to edit; it returns a git bundle and the pull request text. The planner's tier picks the model; a turn limit and the allowance (T60) bound it. A build that needs a decision ends with a structured question and the task is parked. Usage is read from the session log after the job (untrusted, parsed in a separate process under limits). The builder's own check tool is mounted, so a refusal the hand-back would give is fixed in the same session.
- **Reference:** `test_agents.py` (builder command and model cases), `test_injection_canary.py` (issue quotes, CI logs and findings fenced in the builder prompt), `test_prompt_fencing.py`, `test_external_text.py`, `test_precheck.py`.
- **Acceptance:** a scripted CLI returns a bundle and text; every untrusted input reaches the prompt only through the fence (a gate test fails on an unfenced interpolation); a cut session keeps its commits.
- **Evidence:** a real build of a sample task in the job container `(owner provides the token)`.
- **Waits for:** T45, T46. **Size:** L. **Labels:** `class:core`, `priority:high`, `plan:required`.

### T50 Hand-back checks
- **Description:** A new `Core/HandBack` checks what a build returns before anything is trusted: the bundle builds on the branch, never touches main, is a fast-forward of the pushed branch, and holds no merge commit unless a merge of main was asked; attribution trailers and generated-by lines are stripped; the secret scan runs over every new commit, blob and message and over the pull request text, with no allow-list and failing closed when the scanner is missing; credentials mounted into the job are redacted. A failure goes back to the builder once with the reason and hands the task over the second time.
- **Reference:** `test_secret_scan.py` (a token in a commit message, a key deleted by a later commit, scanner settings committed by the builder ignored), `test_redact.py`, `test_attribution.py`, `test_no_force.py`, `test_round.py` (a token in the pull request body is sent back without its value).
- **Acceptance:** each case above; no rejected value appears in the ledger, a comment or the builder's feedback.
- **Evidence:** the refusal of a seeded bundle with a fake key, as recorded.
- **Waits for:** T49. **Size:** L. **Labels:** `class:core`, `priority:high`, `plan:required`, `model:strong`.

### T51 Pre-push check job and the Evidence list
- **Description:** A check job applies the bundle in a fresh clone with a fresh dependency cache and runs the project's check command through `Plugin/Gate` (the PHP gate runs `bin/check --fast` or the configured command). The pull request text must reference every top-level Evidence item of the task (E1..EN) and stay under the body cap; a miss goes back once on its own count, with the commits kept. A file that loses most of its content fails unless the plan names it.
- **Reference:** `test_evidence.py`, `test_round.py` (a second Evidence miss goes to the reviewers; a body over the cap goes back once), `test_fixpass_logic.py`.
- **Acceptance:** each case above; the check's result leaves the container through the output directory only.
- **Evidence:** a red and a green check job on the sample repository.
- **Waits for:** T50. **Size:** M. **Labels:** `class:core`, `priority:high`.

### T52 Composer dependency policy
- **Description:** The Composer ecosystem adapter of the `language` plugin kind (the PHP plugin, the shape the protected-change approval design gives it; not a plugin kind of its own) decides a change to `composer.json` and `composer.lock`: a new package from a trusted vendor (owner config; `symfony/` and `doctrine/` by default, and the official Symfony AI component package types) from its own repository at its upstream tag, with no binaries, Composer plugin or autoload files, is routine; version constraint updates, a trusted package moved unchanged between `require` and `require-dev`, and a beta or RC stability flag for a package the same change adds are routine; any other package, a removed package, and any change to Composer's own settings needs the owner, listing the packages. "Removed" and "new" are relative to the old files read at the merge base of the head with main, never at main's tip, for every owner decision about a change (packages, protected paths, workflow changes). The rules are owner config.
- **Reference:** `test_policy.py` (constraints pass, a commit that is not the upstream tag waits, removed and moved packages and lock settings go to the owner), `test_owner_approval.py` (changes beyond routine name the new packages; forced order: a branch behind main that adds one package is asked about that package alone, and the packages main added since are not listed as removed).
- **Acceptance:** each case above; an unreadable lock file needs the owner.
- **Evidence:** the policy's decision on three sample lock diffs.
- **Waits for:** T51. **Size:** M. **Labels:** `class:core`, `priority:normal`.

### T53 Signed push and the pull request
- **Description:** The orchestrator signs the branch commits with its own key, pushes the branch without force, and opens or updates the pull request as its App with the builder's text. Before the push, main is test-merged: a real conflict goes back to the builder as a merge-main rework. Each push records its phase and the SHA it intends to produce first, so a crash never pushes twice or opens a second pull request.
- **Reference:** `test_gitops.py` (push refuses unsigned commits, conflicts with main), `test_lanes.py` (a crash after the push or after opening the pull request), `test_github.py` (pull request create and update).
- **Acceptance:** each case above with a temporary repository and recorded forge responses.
- **Evidence:** a pushed branch on the test repository with verified signatures.
- **Waits for:** T50, T37, T51, T55 (nothing is pushed before the pre-push check and the workflow approval exist; the dependency policy decides at merge). **Size:** M. **Labels:** `class:core`, `priority:high`.

### T54 Protected paths from the whole change
- **Description:** Whether a head touches a protected path is read from the full diff between the merge base and the head plus the per-commit lists (each commit against each parent, second parents included unless main has them); submodules and `.gitmodules` count. The protected list, the owner-only list and the approval rules are owner config, never repository files.
- **Reference:** `test_crafted_merge.py` (a revert and a deletion a crafted merge hides), `test_policy.py` (exact files, workflows, main never pushed).
- **Acceptance:** each case above; a path the check cannot read counts as protected.
- **Evidence:** the protected list of a crafted-merge sample.
- **Waits for:** T46. **Size:** M. **Labels:** `class:core`, `priority:high`, `model:strong`.

### T55 Workflow risk scan and the push approval
- **Description:** Behind the GitHub forge, a strict line scan of the workflows (the head's, main's and both merged) finds anything beyond a hosted runner with a read-only token and no secrets; any form it cannot read, and any `runs-on` from a variable or expression (even while the variable is unset: its value can change without a commit), is risky. The files the workflows run from the repository (scripts, the CI image's Dockerfile) are part of the CI definition: a change to one is a CI change. A build with a risky CI change stays local: the issue shows its complete diff, and only the owner's `approve <sha>` lets it push; the merge still needs the owner.
- **Reference:** `test_workflow_risk.py` (hosted labels, any other label asks, an unreadable form asks, read-only permissions), `test_owner_approval.py` (approval before the push cases).
- **Acceptance:** each case above; a failed variable read holds the push; a `runs-on` taken from a variable is risky even while the variable is unset; a change to a script a workflow runs, with no workflow file changed, is held for the owner's `approve <sha>`.
- **Evidence:** the scan's findings on three sample workflows.
- **Waits for:** T54. **Size:** M. **Labels:** `class:core`, `priority:high`, `model:strong`.

### T56 Build lane
- **Description:** A new `Core/Lanes` module runs the build lane on Messenger, with the Workflow component as each task's state machine: a free slot takes the task T47 orders, across projects by their weekly share (a project with nothing to build leaves its share to the others); a configured number of build slots, never two on one branch; the lane waits while the review cap of heads with finished CI is full, except for one `urgent` build at a time; pause stops admission, drain stops new builds while the review lane runs. A job that cannot start for its own reason never idles the slot. A task not started yet whose confident file forecast overlaps a head in flight gives the slot only to a candidate of the same priority and class free of overlap, and is built anyway when there is none; work on an open pull request is never skipped for an overlap.
- **Reference:** `test_lanes.py` (drain stops build admission on both slots while the review lane runs), `test_build_slots.py` (a crash in one slot recovered without touching the other), `test_priority.py` (urgent work gets the free slot before the share order), `test_projects.py`, `test_guard.py` (pause wins over drain).
- **Acceptance:** forced-order tests for two slots and two projects; a killed consumer resumes from the database; forced-order tests for the overlap rule: a task with an open pull request and an overlapping file in flight is picked; a high-priority task with an overlap is picked over a free normal-priority one; a free task of the same priority is picked over an overlapping one.
- **Evidence:** the ledger of two parallel builds on the test repository.
- **Waits for:** T47, T49, T53. **Size:** L. **Labels:** `class:core`, `priority:high`, `plan:required`.

### T57 Review lane through CI
- **Description:** The review lane takes pushed heads, never one of a project at weekly share 0 (owner decision 6: such a project gets no reviews, open pull requests included, until its share is raised): a head with pending CI goes back to its queue with a time to look again and holds nothing; heads with finished CI go first, one pending for 30 minutes is admitted by age. Red CI sends the head back before any reviewer, with a redacted, fenced CI log excerpt only when the workflow scan (T55) finds every job of the head's, main's and the merged workflows on a forge-hosted runner with a read-only token and no secrets; otherwise, or when the scan fails, with one line giving the number of failed jobs; two red-CI send-backs in a row free per cycle. A missed CI start is read once, nudged once and then handed over. A round runs at most the configured review cycles, then hands over.
- **Reference:** `test_lanes_cap2.py` (finished CI first, nudges, an unreadable workflow fails closed), `test_ci_log.py`, `test_round.py` (red CI uses no review cycle), `test_lanes.py` (the third red-CI bounce counts), `test_causes.py` (cycles running out record their cause).
- **Acceptance:** each case above with recorded CI responses and a controlled clock; a head of a project at share 0 is not admitted, and is once the share is raised; a head whose workflow has a variable `runs-on`, a head whose workflow scan fails, and a head with a job on a non-hosted runner each get only the count of failed jobs, never an excerpt.
- **Evidence:** a head sent back for red CI with its excerpt, on the test repository.
- **Waits for:** T56, T38, T55. **Size:** L. **Labels:** `class:core`, `priority:high`, `plan:required`.

### T58 Review jobs with boot gate and probes
- **Description:** Reviewers run in review job containers with a read-only checkout of the exact head, reaching only their vendor through the job proxy (T44); the project's `bin/probe` runs in a separate no-network sandbox within a per-review budget. Before any reviewer, the head boots once with `bin/instance`; main's result is cached per main commit and tool fingerprint; a head that fails while main boots goes back like red CI; when main fails too, the gate is off for the head and the owner gets an infrastructure alert once. A canary probe checks the probe path after each boot; a broken path marks probes unavailable instead of producing findings.
- **Reference:** `test_boot_gate.py` (booted before any review, a failed boot beats approvals, an approved head that does not boot is not merged, a crashing gate never stops the review), `test_probe.py`, `test_probe_alerts.py` (a Composer failure alerts only for main's lock), `test_merge_only.py` (a cached boot pass runs no canary).
- **Acceptance:** each case above; nothing a probe writes reaches the branch; a probe cannot reach the vendor or any other host.
- **Evidence:** a review whose finding carries a probe's output.
- **Waits for:** T44, T45, T57. **Size:** L. **Labels:** `class:core`, `priority:high`, `plan:required`, `model:strong`.

### T59 Rework feedback, scoped re-reviews and holds
- **Description:** A send-back gives the builder the judge's act list (open findings merged across reviewers, P1 first, one per place) and the published pull request text. A reviewer's later review is scoped to the delta from the head it last reviewed, with the fallbacks of the design; an approval counts only for the exact head and pull request text. A build that leaves the head unchanged while a finding needs something outside the change goes on hold and is released by an event that could change the outcome: a move of main releases an outside-change or red-CI hold into a merge-main build, never into a review of the unchanged head; a deploy alone releases into one review; the fruitless-release count and its hand-over stay. A `Waits for` line naming the task's own pull request creates no hold and ends as a hand-over; a wait on a pull request resolves to the task it closes. A `Needs the owner:` evidence item becomes a Needs-you card instead of a send-back.
- **Reference:** `test_review_history.py`, `test_holds.py` (an outside requirement holds without a review; a real code change is reviewed; forced order: a build naming its own pull request in `Waits for` hands over and creates no hold; a hold waiting on a pull request whose task is stalled is reported with that task's cause; a hold for an outside change, then a move of main, queues a merge-main build and no review), `test_round.py` (open findings merged across reviewers; an approval does not survive a rewritten pull request text), `test_review_process.py` (a needs-the-owner line reaches status).
- **Acceptance:** each case above.
- **Evidence:** a two-round rework on the test repository with the scoped second review.
- **Waits for:** T57, T12. **Size:** M. **Labels:** `class:core`, `priority:high`.

### T60 Model choice and the progress stop
- **Description:** Routing rules in config pick the build tier: the cheaper tier by default; the strong tier for a `model:` label, a sensitive path or an earlier failed attempt; a blocking finding or a stall raises the next rework. A build over its allowance continues while it makes progress (a content-changing commit, or a change in which tests fail); no progress for a set time ends it with its commits kept; a runaway ceiling and a per-task total stop it regardless. Every stop records its cause.
- **Reference:** `test_model_choice.py`, `test_escalation.py` (a rework after a round without a P1 stays on the cheaper tier; `model:` label from cycle 1), `test_budget_174.py` (no progress for n minutes, progress resets the idle time, the runaway cap).
- **Acceptance:** each case above; a job never picks its own model.
- **Evidence:** the model events of three builds of differing risk.
- **Waits for:** T56, T40. **Size:** M. **Labels:** `class:core`, `priority:normal`.

### T61 Escalation of a recurring P1
- **Description:** A P1 flagged in the same place on two heads goes to an escalation review on the strong tier for that finding alone; its verdict is kept per finding (hash of its text and of every file it names) and reused on unchanged files. Confirmed: the next build runs on the strong tier and the raising reviewer re-reviews. Rejected: the holder re-reviews with the reasons. A third time hands the task over.
- **Reference:** `test_escalation.py`.
- **Acceptance:** each case above; a finding naming no regular file gets no key and is judged again.
- **Evidence:** an escalation record from a scripted three-round task.
- **Waits for:** T59, T60. **Size:** M. **Labels:** `class:core`, `priority:normal`.

### T62 Merge: signed fast-forward of the exact head
- **Description:** A new `Core/Merge` module: right before merging it re-reads the task and pull request, requires green CI on exactly the head, merges main into the branch only now (a clean merge keeps verdicts and approvals; CI runs once more), signs, records the merge, then moves main to the head by a non-force ref update, so a push in between fails the merge; it reads the result back, closes the issues and deletes the merged branch, but only after the forge reports the pull request merged: if it has not reported it within a short bound, the branch is kept and the pull request gets one comment naming the head that landed on main, and a forced-order test covers a late report. A budget back-off still merges approved heads. A head that touches protected paths (T54) is parked and never merged until T64 adds the owner's approval, so no increment between T62 and T64 merges a protected change. Updates design decision 9 (merges are signed fast-forwards of main, not forge merge commits), as the owner decided.
- **Reference:** `test_fixpass_git.py` (refuses a non-fast-forward, rejected when main moves after the checks, a push whose response was lost stays covered), `test_merge_only.py`, `test_lanes.py` (a crash after publishing never publishes twice).
- **Acceptance:** each case above with a temporary repository and recorded forge responses; a head touching a protected path with green CI and every verdict stays parked.
- **Evidence:** a merged test pull request with main's verified signature.
- **Waits for:** T57, T54. **Size:** L. **Labels:** `class:core`, `priority:high`, `plan:required`, `model:strong`.

### T63 Watchdog over main
- **Description:** A new `Core/Watchdog` checks main's new commits before every step and merge: a merge Intakt recorded, or a commit signed by a key the owner registered, passes (the owner's own push is an info event); an unsigned commit, an unknown key or a rewrite of main's history halts the project with an owner card that only the owner clears.
- **Reference:** `test_watchdog.py` (a recorded merge is clean, unsigned halts, a foreign key, a force push, an owner-signed commit is a notice given once across clones).
- **Acceptance:** each case above.
- **Evidence:** a halt and its card after a seeded unsigned commit on a temporary repository.
- **Waits for:** T62. **Size:** M. **Labels:** `class:core`, `priority:high`, `model:strong`.

### T64 Owner approval of protected heads
- **Description:** A head with protected changes goes through the normal flow and, with CI green, every review approved and a clean merge with main, waits for the owner and notifies once; it holds no build slot. Only the owner's account approves: a review approving the head commit or a comment `approve <sha>` written after the push; Intakt never submits a review or writes such a comment (the forge client refuses it). An early approval counts once its head passes; a new push voids it except a clean merge of main that keeps the protected change identical; `changes <sha>` sends the head back. At most three heads wait (cap is config); a ready head beyond the cap is queued by priority then age and asked when a place frees, reserved under a lock. With a missing reviewer vendor the decision stays the owner's; nothing defaults on a timer.
- **Reference:** `test_owner_approval.py` (only the owner's approval of exactly this head counts, the loop never submits a review, a waiting head holds no slot, a withdrawn approval parks without a second notice), `test_maintainer_ask_once.py` (the cap queue, a conflict after the approval parks it).
- **Acceptance:** each case above with recorded forge responses.
- **Evidence:** an approved protected merge on the test repository.
- **Waits for:** T62, T55, T52. **Size:** L. **Labels:** `class:core`, `priority:high`, `plan:required`, `model:strong`.

### T65 Pull requests Intakt did not build
- **Description:** A pull request opened by the owner's or the maintainer's identity is reviewed on every new head without a label; anyone else's only after a person with write access labels it. Findings go back as one comment and labels stay; without `take-over` Intakt never reworks it. Once CI and every review pass, an unprotected head merges and a protected one asks the owner once. An `urgent` or `high` label counts only from the owner or the maintainer.
  It also covers handing items between a person and Intakt ([Labels](design/05-architecture/04-labels.md#who-drives-an-item)). `hands-off` from a person with write access stops everything on any issue or pull request: nothing starts, a running job ends at its next step, nothing it produced after the label is pushed or posted (unpushed work is kept as a bundle), a merge re-checks the label right before publishing, and it beats every other label and rule. Removing it, or setting `take-over`, makes the current head the task's head: commits made by hand are listed for the builder (writers' commits as its own, others fenced), the head gets one full review by every reviewer, earlier findings are checked against it, and hand-made commits use no review cycle. `take-over` on a pull request Intakt did not build makes it an Intakt task (reworked, reviewed, merged like Intakt's own); a fork it cannot push to is carried over to an Intakt branch and a new pull request linking the original, with a `Co-authored-by` trailer per original author and one comment on the original; a pull request touching protected paths is not taken over (Intakt says so once, naming the files). `take-over` on an issue by someone without write access stands in for the authorship check, with the text fenced, and an edit after it needs the label again.
- **Reference:** `test_maintainer_merge.py` (a labelled pull request merges like a loop merge, a branch behind main gets main merged in, a crashing pull request is held and handed back the third time), `test_maintainer_unprotected.py`, `test_maintainer_priority.py`, `test_crafted_merge.py` (a maintainer pull request with a crafted merge needs the owner).
- **Acceptance:** each case above; a label from an account without write access changes nothing; `hands-off` on a running build keeps its unpushed work as a bundle and publishes nothing produced after the label; `hands-off` set while a merge is about to publish stops the merge; `hands-off` beats `urgent` and the maintainer agent; after hand-back the head gets one full review and hand-made commits use no cycle; a non-writer's commits reach the builder fenced; `take-over` on a pull request of another author gets reworked and merged, on a fork it makes a linked pull request with the trailers and one comment, on a protected-path change it is refused once naming the files; `take-over` on an issue by a non-writer builds it with the text fenced and an edit afterwards needs the label again.
- **Evidence:** an owner-opened test pull request reviewed and merged (owner provides: needs a live forge); tests with a fake forge for each hand-over case above.
- **Waits for:** T64. **Size:** L. **Labels:** `class:core`, `priority:high`.

### T66 Catch-up reviews
- **Description:** When the T14 substitution replaced a reviewer whose vendor was out, the merge records an owed catch-up review; when that vendor has room again, catch-ups run one at a time, merges touching sensitive paths first, then oldest, never ahead of a live head waiting for that reviewer ([Reviews](design/05-architecture/07-reviews.md)). The record is written before the publish, so a crash neither loses nor doubles a catch-up. A rejecting catch-up files a task naming the merged pull request, with the review inside an external block, redacted and scanned like review text (a hit files nothing and is a failed catch-up); the task inherits no steering label and is built only once a person labels it. An approval's follow-ups go to the follow-up inbox. A catch-up that returns `blocked` or no valid verdict is retried once on another account of the same vendor, then handed to the maintainer agent, and given up after three attempts with one owner notice. Each catch-up's state is on the dashboard.
- **Reference:** `test_gpt_substitute.py` (the substitute's verdict counts and the head merges; a rejection beside an out vendor goes to the builder without a substitute; a crash retry finds only its own issue), `test_reviewer_accounts.py`.
- **Acceptance:** each case above; a rejecting catch-up files one task that stays unbuilt until it is labelled; a secret-scan hit files nothing; a catch-up's follow-ups reach the inbox; the failed path ends in one notice.
- **Evidence:** a catch-up run after a substituted merge on the test repository.
- **Waits for:** T62. **Size:** M. **Labels:** `class:core`, `priority:normal`.

### T67 Hand-overs, causes and notices
- **Description:** Every outcome that is not a merge records a cause (no progress, ceiling, failed checks, protected paths, review cycles, conflict, question); a hand-over labels the issue, comments once and adds a Needs-you item. Notices go through `Plugin/Notifier` (Home Assistant mobile push first), pushed only for owner actions, once per item and state, with their delivery result recorded.
- **Reference:** `test_causes.py`, `test_notify.py` (every notice has a kind; a failed push is reported, not raised), `test_lanes.py` (a crash between the end and the hand-over only completes the hand-over).
- **Acceptance:** each case above.
- **Evidence:** a test notice and a hand-over card.
- **Waits for:** T56. **Size:** M. **Labels:** `class:feature`, `priority:high`.

### T68 Status for the owner's dashboard
- **Description:** `intakt:status --format=json` and a local endpoint report per project the lanes, queues, steps, guard decisions, heads waiting for the owner, Needs-you items and the last outcomes, with no secrets. A documented schema covers the fields the owner's dashboard reads today; that dashboard reads this JSON until Intakt's own dashboard replaces it.
- **Reference:** `test_cli.py` (status reports pause, drain, parked issues).
- **Acceptance:** the JSON validates against the schema; a stopped consumer still yields a status.
- **Evidence:** the status of the running install.
- **Waits for:** T67. **Size:** M. **Labels:** `class:feature`, `priority:high`.

### T69 Owner controls
- **Description:** Console commands for pause and resume, drain and undrain, `drain --wait -- <command>` for deploys (waits until idle, runs the command, undrains), and a ceiling override with an expiry; a drain older than two hours is an owner notice.
- **Reference:** `test_lanes.py` (drain cases), `test_override.py` (an override ends the budget back-off it lifts and keeps a crash back-off).
- **Acceptance:** each case above.
- **Evidence:** a drained deploy of a new image.
- **Waits for:** T56, T40. **Size:** M. **Labels:** `class:feature`, `priority:normal`.

### T82 A runaway command fails fast, and a memory kill is told and handed over
- **Description:** The memory rules of the design ([Security model](design/04-constraints.md#security-model)) for build and check jobs. Inside each job the agent's commands run in a memory group of their own below the job's limit (a nested cgroup v2 group), and a command killed there comes back to the agent as the command's result naming the process. The PHP `language` plugin sets `memory_limit` for the PHP processes a job starts: by default the larger of 512 MB and twice the memory peak of the project's first green check on main, measured when the project is set up; a limit the project sets itself wins, a project-file setting overrides the default, and the status (T68) reports the value in use and where it comes from. A job killed for memory posts one comment on its task naming the process and the step (redacted and scanned like other outbound text), and the next attempt gets that note, with the process's command line fenced; a second memory kill of the same task raises a `maintainer` notice instead of another attempt, shown as status and never as an owner card (it stays parked until the maintainer agent exists). Where the host cannot give a job nested groups, the commands share the job's limit and the install says so once. Updates the threat model's builder inputs.
- **Acceptance:** forced-order tests with a fake memory kill: a first kill yields one comment naming the process and the step, and the next attempt's prompt carries the note, fenced; a second kill of the same task yields the hand-over and starts no attempt; a crash between the kill and the comment posts the comment once. A measured peak of 200 MB sets 512 MB and one of 400 MB sets 800 MB; a project's own limit and a project-file setting each win over the default. A host without nested groups falls back to the job's limit with one notice.
- **Evidence:** the forced-order tests' run; the default limit computed from a measured peak of the sample repository's check; a command over its group's limit killed while the agent keeps running, on a host with cgroup v2 delegation `(owner provides)`.
- **Waits for:** T44, T45, T49, T51, T67, T68, T76 (the `language` plugin kind and the status it builds on). **Size:** M. **Labels:** `class:core`, `priority:normal`.

## M8 Switch

Each stage starts with the owner's go and can be rolled back to the previous one by config.

### T70 Living beside the existing loop
- **Description:** A project names its own eligibility label (default `intakt`), distinct from the existing loop's; Intakt's watchdog accepts the loop's signing key as a peer (its merges are info events); Intakt leaves pull requests the loop owns and opens its own under its App identity. The loop side needs the matching settings (Intakt's signing key as a peer, without a push notice, and skipping pull requests opened by Intakt's App), outside this repository.
- **Acceptance:** a loop merge on main passes Intakt's watchdog as an info event; a task with the loop's label only is not eligible.
- **Evidence:** both watchdogs clean after one merge by each, `(owner provides)` the loop side.
- **Waits for:** T63, T47. **Size:** S. **Labels:** `class:core`, `priority:high`.

### T71 Stage 2: Intakt's verdicts count
- **Description:** For the pull requests handed to Intakt (those opened under Intakt's App identity, which the loop leaves), Intakt reviews, posts its review with the round's one-time token, asks the owner for protected heads and merges. The weekly report keeps running on the loop's own heads.
- **Acceptance:** a handed-over pull request merges through Intakt alone; the loop makes no review or merge on it.
- **Evidence:** `(owner provides)` one week of counted reviews and merges.
- **Waits for:** T70, T65, M6 exit. **Size:** M. **Labels:** `class:core`, `priority:high`.

### T72 Stage 3: a labeled share
- **Description:** Intakt builds the tasks carrying its eligibility label, end to end; a weekly report compares Intakt's and the loop's outcomes per task: builds and send-backs per task, hand-overs by cause, cycles to merge, tokens per merged task.
- **Acceptance:** the report reads only the ledger and the forge; it shows no limits or reset times.
- **Evidence:** `(owner provides)` the first week's report.
- **Waits for:** T71 and M7. **Size:** M. **Labels:** `class:feature`, `priority:high`.

### T73 The parity record
- **Description:** Each item in `docs/architecture/parity.md` gets the Intakt test that shows the behavior beside the existing loop's test; a gate test fails when an item names no Intakt test that exists.
- **Acceptance:** every item names a test that exists and passes.
- **Evidence:** the gate test run.
- **Waits for:** T69. **Size:** S. **Labels:** `class:tooling`, `priority:high`.

### T74 Takeover
- **Description:** A runbook in `docs/user/`: the eligibility label becomes the loop's, Intakt switches to the loop's GitHub Apps and the co-existence Apps are removed, the loop is drained and stopped with its state kept, and the rollback (restart the loop, return the label) is tested once.
- **Acceptance:** the rollback was run once and both directions are written down.
- **Evidence:** `(owner provides)` the takeover and a first merged task after it.
- **Waits for:** T72, T73. **Size:** S. **Labels:** `class:tooling`, `priority:high`.

## Not in the switch

- **Later:** approval by class and the additive gate proof; pacing and stand-ins for a second reviewer vendor; cross-checks; the plan trial arm, holdout and quality report; cost reports from CLI logs; webhooks (T32); branch cleanup beyond merged branches (T30); conflict resolution for generated files; deploy hooks after a merge; database sidecars for builds; the maintainer agent; a dashboard beyond the status JSON; the setup wizard; other forges, languages and vendors.
- **Dropped** (the existing loop's own constraints, replaced by the design): its round mode, tick and shared host locks, acknowledgment ranges and ledger branch seeding, its pre-App token history, its install scripts and resource slices, its CI slot wrappers, review verdicts parsed from Markdown, and its old config shims.

## Parallel paths (M6 to M8)

- From 2026-11-23: T37, T40 and T44 in parallel; T38 after T37.
- The first weeks of the weekly report (after T43) run while M7 is built.
- M6: T45 and T46 after T44, alongside T37, T38, T40 and T43.
- M7: T39 after T33 and T14, in parallel with T47 and T54; T48 after T47; T49 to T52 in order, T49 after T45 and T46; T55 after T54; T53 after T51 and T55; T56 to T58 in order; T59 to T61 after T57; T62 to T65 in order (T64 also after T52 and T55); T66 after T62; T67 to T69 after T56; T82 after T51, T67, T68 and T76.
- M8: T70 after T63; T71 after the M6 exit; T72 after M7; T73 after T69; T74 last.
