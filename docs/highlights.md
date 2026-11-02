# Highlights: Symfony and Symfony AI in Intakt

Intakt runs on PHP 8.5, Symfony 8.1, Symfony AI 1.0.0-BETA1 and the PHP MCP SDK. This page lists every Symfony, Symfony AI and FrankenPHP feature Intakt uses or plans to use, and what it found in the beta. A change that starts or stops using a feature updates its row in the same change (see `AGENTS.md`).

Status is `planned`, `in progress` (an open pull request) or `done` (on main). **Where** names the plan task and its issue, and once built the main file. A row cites the issue, not the pull request: the issue links its pull requests, and a build cannot know its pull request number before the pull request exists.

## Symfony AI Platform

| Feature | How Intakt uses it | Status | Where |
|---|---|---|---|
| Several providers at once | Anthropic, OpenAI and an OpenAI-compatible endpoint in one install | in progress | T4 (#9, part #51), `config/packages/ai.php` |
| Structured output into typed objects (`asObject()`, Validator subscriber) | verdicts and judge results as PHP objects with enums and nested lists | in progress | T1 (#5), `src/Core/Review/Contract/VerdictParser.php`, `src/Core/Review/Contract/Verdict.php`, `src/Core/Review/Infrastructure/StrictVerdictSubscriber.php`, `src/Plugin/SymfonyAiVendor/Infrastructure/SymfonyAiVendor.php`; T5 (#10, part #98), T12 (#17, part #445), `src/Core/Review/Contract/Judgement.php`, `src/Core/Review/Infrastructure/JudgeAgent.php` |
| Token usage (`getTokenUsages()`, `TokenUsage`, cached tokens, `remaining*`) | per-call cost in the ledger, remaining tokens into the budget planner | in progress | T5 (#10, part #99), `src/Plugin/SymfonyAiVendor/Infrastructure/UsageLedger.php` |
| Typed errors (`RateLimitExceededException::getRetryAfter()`, `RetryableExceptionInterface`, `AuthenticationException`) | cooldowns and retries without parsing text; a rejected key cools its account down, and a provider whose every key was rejected fails instead of waiting; a Codex usage limit from the CLI's own failed-turn report becomes a `RateLimitExceededException`, and the `DeferredResult`'s `onConvert()` tells the cooldowns an account answered, so its account error is reported again only after that | in progress | T5 (#10, part #100), `src/Plugin/SymfonyAiVendor/Infrastructure/SymfonyAiVendor.php`; T9 (#14), T14 (#19, part #470), `src/Core/Ai/Infrastructure/AccountPlatform.php`, `src/Core/Ai/Infrastructure/AccountFailoverPlatform.php`; T39 (#321, part #502), `src/Core/Ai/Infrastructure/CodexStructuredError.php` |
| Anthropic prompt caching (`cache_retention`) | the system prompt, the tools and the latest message are cached, so round two of a review reads the tokens round one cached | in progress | T7 (#12), `config/packages/ai.php`, `tests/Core/Review/Infrastructure/ReviewerPromptCachingTest.php` |
| `FailoverPlatform` | failover between the accounts of one provider inside Intakt's own wrapper, which skips cooling accounts, cools down the account a limit came from and builds a new `FailoverPlatform` per call (it keys its limiter by platform class and calls a failed platform again, `upstream/ai-failover-platform-retries-a-failed-platform/`); a substitute provider is chosen in plain code, not through `FailoverPlatform` | in progress | T14 (#19, parts #414 and #415), `src/Core/Ai/Infrastructure/AccountFailoverPlatform.php`, `src/Core/Ai/Infrastructure/AccountPlatform.php`, `src/Core/Ai/Infrastructure/TierPlatform.php` |
| Streaming | reviewer text shown live | planned | T21 (#27) |
| `MockPlatformFactory` with `ScriptedResponse` | offline tests of every model call | in progress | T4 (#9), T5 (#10, part #98), `tests/Plugin/SymfonyAiVendor/SymfonyAiVendorTest.php`; T7 (#12), `tests/Core/Review/Infrastructure/ReviewerVerdictTest.php` |
| `RecordingProvider` with `HttpCassette` | real responses recorded once, replayed offline with redaction | planned | T25 (#32), T28 (#35) |
| Claude Code and Codex platform bridges | a reviewer on a subscription account (its automation token where the vendor has one), `IncompleteStreamException` exercised | in progress | T33 (#61), `src/Core/Ai/Infrastructure/ClaudeCodePlatformFactory.php`, `src/Plugin/SymfonyAiVendor/Infrastructure/SymfonyAiVendor.php` (Claude Code); T39 (#321, parts #500 and #501), `src/Core/Ai/Infrastructure/IsolatedCodexCli.php` (Codex: the login per account, its refresh and a probe call), `src/Core/Ai/Infrastructure/CodexPlatformFactory.php` (Codex: the reviewer, `IncompleteStreamException` for a stream whose turn never completed) |

## Symfony AI Agent

| Feature | How Intakt uses it | Status | Where |
|---|---|---|---|
| Agent with tools and tool effect hints | one agent per reviewer role and for the judge, with read-only repository tools | in progress | T7 (#12), T12 (#17, part #445), `src/Core/Review/Infrastructure/ReviewerAgent.php`, `src/Core/Review/Infrastructure/JudgeAgent.php`, `src/Core/Review/Infrastructure/Repository/` |
| `FaultTolerantToolbox` | a failing tool answers the model with an error instead of ending the run | in progress | T7 (#12), `src/Core/Review/Infrastructure/ReviewerAgent.php` |
| `TraceableToolbox` | tests hand the reviewer a traced toolbox, so `assertToolCalled()` sees its tool calls | in progress | T7 (#12), `tests/Core/Review/Infrastructure/ReviewerVerdictTest.php` |
| `ToolCallRequested` event | a listener denies tool arguments that escape the checkout | in progress | T7 (#12), `src/Core/Review/Infrastructure/Repository/DenyPathsOutsideCheckout.php` |
| `ToolCallArgumentResolver` | the listener validates a call's arguments before it denies a path, so a malformed call never passes as a denial | in progress | T7 (#12), `src/Core/Review/Infrastructure/ReviewerToolboxFactory.php` |
| Message store (`MessageStoreInterface`, Doctrine DBAL store) | the conversation of each reviewer in each round, kept in the `reviewer_conversation` table | in progress | T7 (#12), T29 (#29), `src/Core/Review/Infrastructure/ReviewerAgent.php`, `config/packages/ai.php`, `migrations/Version20261029120000.php`; T43 (#384): a build's conversation kept at a vendor limit |
| Built-in MCP client tool (`mcp_server` in `ai.agent.<name>.tools`) | reviewers reach the per-review MCP server | planned | T16 (#21) |
| `MemoryInputProcessor` | after a vendor limit, the hand-off note and the kept conversation for the next account; not the reviewer's briefing, because its fixed preamble tells the model that memory outweighs the system prompt and the briefing holds untrusted notes (T17 #463 sends the fenced notes as a user message from `src/Core/Review/Infrastructure/ReviewerMemory.php` instead) | planned | T43 (#384) |
| Streaming `Progress` and `Execution::cancel()` | live progress on the dashboard and a stop button | planned | T21 (#27) |

## Symfony AI Store

| Feature | How Intakt uses it | Status | Where |
|---|---|---|---|
| `DocumentIndexer` and `Retriever` | index memory entries, retrieve those that describe the files under review | in progress | T17 (#22, #461), `src/Core/Memory/Infrastructure/StoreMemoryIndex.php`, `src/Core/Memory/Infrastructure/StoreProjectMemory.php`, `src/Core/Review/UseCase/IndexResolvedFindings/IndexResolvedFindingsHandler.php` (T17 #462: resolved findings are indexed after a completed review); T17 #463: `Retriever` feeds each reviewer's briefing through `src/Core/Review/Infrastructure/ReviewerMemory.php`, `config/packages/ai.php` |
| `symfony/ai-sqlite-store` | embedded store on SQLite; it loads all vectors into PHP memory, which fits one install's memory | in progress | T17 (#22, #461), `tests/Core/Memory/Infrastructure/SqliteMemoryStoreTest.php`, `config/packages/ai.php` (store, vectorizer and indexer for production, T17 #462) |
| In-memory store | retrieval tests | in progress | T17 (#22, #461), `tests/Core/Memory/Infrastructure/StoreProjectMemoryTest.php` |
| Reranker | only when a configured provider supports reranking | planned | T17 (#22) |

## AI Bundle

| Feature | How Intakt uses it | Status | Where |
|---|---|---|---|
| Platform configuration, keys from Symfony secrets or the environment | providers configured per install; a missing key disables its provider | in progress | T4 (#51), `config/packages/ai.php` |
| `model_catalog` and a model router | capability tiers mapped to provider models per project | in progress | T4 (#52), `config/packages/ai.php`, `src/Core/Ai/Infrastructure/TierPlatform.php` |
| `AiAssertionsTrait` (`assertToolCalled`, `assertPlatformCallCount`) | tests assert tool calls and the number of platform calls | in progress | T7 (#12), `tests/Core/Review/Infrastructure/Repository/ReviewerAgentTest.php`, `tests/Core/Review/Infrastructure/ReviewerVerdictTest.php` |

## MCP Bundle and MCP SDK

| Feature | How Intakt uses it | Status | Where |
|---|---|---|---|
| MCP Bundle server for the owner | agents list projects, read a round's status, verdicts, act list and findings, and start a review, with progress notifications | in progress | T15 (#20, part #508), `config/packages/mcp.yaml`, `src/Core/Ai/UseCase/ListProjects/ListProjectsTool.php`; T15 (#20, part #509), `src/Core/Review/UseCase/ReadRound/`; T15 (#20, part #510), `src/Plugin/LocalGitForge/UseCase/ReviewChange/StartReviewTool.php` |
| MCP Bundle server per review | serves the read-only repository tools of the head under review, from its own registry, path and session store beside the owner server; the round comes from the request's signed credential, never from a tool argument | in progress | T16 (#21, part #578), `config/packages/mcp.yaml`, `src/Core/Review/UseCase/ServeReviewTools/` |
| `session.lock` | safe parallel calls on one session | in progress | T15 (#20, part #508), `config/packages/mcp.yaml`; T16 (#21, part #578), the same file |
| `#[IsGrantedTool]` | tool authorization through Intakt's Security voter; the AI Bundle's attribute, checked for MCP tools by a wrapper around the SDK's reference handler because the bundle checks it only in its agent toolbox | in progress | T15 (#20, part #508), `src/Core/Ai/UseCase/ListProjects/ListProjectsTool.php`, `src/Core/Mcp/Infrastructure/AuthorizedToolCalls.php`; T16 (#21, part #578), `src/Core/Review/UseCase/ServeReviewTools/ReadFileTool.php` ; T16 (#21, part #579), `src/Core/Mcp/Infrastructure/ToolAccessVoter.php` (the voter decides for the review server too; each decision is logged) |
| Tool annotations (`readOnlyHint`, `destructiveHint`) | every tool states its effect | in progress | T15 (#20, parts #508 and #510), `src/Core/Ai/UseCase/ListProjects/ListProjectsTool.php`, `src/Plugin/LocalGitForge/UseCase/ReviewChange/StartReviewTool.php`; T16 (#21, part #578), `src/Core/Review/UseCase/ServeReviewTools/` |
| Progress notifications | long tools report progress: `start_review` sends one step per finished reviewer and one for the judge; `search_files` on the review server sends one per ten files searched | in progress | T15 (#20, part #510), `src/Plugin/LocalGitForge/UseCase/ReviewChange/ForwardedProgress.php`; T16 (#21, part #578), `src/Core/Review/UseCase/ServeReviewTools/SearchFilesTool.php` |
| MCP SDK client | contract tests that call every tool, through `Mcp\Client` over a PSR-18 client into the kernel | in progress | T15 (#20, part #510), `tests/Core/Mcp/Contract/OwnerServerSdkClientTest.php` |

## Mate

| Feature | How Intakt uses it | Status | Where |
|---|---|---|---|
| Mate as a dev dependency | development diagnostics against the running app (`vendor/bin/mate`, `mate/`) | in progress | T27 (#34), `mate/` |

## Symfony components

| Feature | How Intakt uses it | Status | Where |
|---|---|---|---|
| Messenger | command and query buses; a rate-limited reviewer retries after `getRetryAfter()` through `RecoverableExceptionInterface::getRetryDelay()`; the failure transport means `blocked`. Built: the two buses `command.bus` and `query.bus`, the typed handler attributes `AsCommandHandler` and `AsQueryHandler`, the `async` Doctrine transport that carries one message per reviewer of a round (its table from the migrations, `auto_setup` off, `redeliver_timeout` above the reviewer hold), `RecoverableMessageHandlingException` with a retry delay for a message that finds its reviewer held by a live worker, `ReviewerRateLimited` (retry delay from `getRetryAfter()`), `JudgeLimited` (the same for the judge call of a round), and the `failed` failure transport with `BlockReviewerOfFailedMessage` and `LogFailedJudging` (logs a judging that failed for good); the retry strategy's `max_delay` caps the wait of a retry without its own delay at one minute; the first query handler, `VerdictsOfRoundHandler`, reads a round's verdicts with their standing | in progress | T9 (#14, part #271), `config/packages/messenger.yaml`, `src/Core/Platform/Contract/AsCommandHandler.php`, `src/Core/Platform/Contract/AsQueryHandler.php`; T9 (#14, part #240), `src/Core/Review/UseCase/RunReviewRound/`; T9 (#14, part #241), `src/Core/Review/UseCase/RunReviewRound/ReviewerRateLimited.php`, `src/Core/Review/UseCase/RunReviewRound/BlockReviewerOfFailedMessage.php`; T11 (#16), `src/Core/Review/UseCase/ReadVerdicts/`; T12 (#17, part #446), `src/Core/Review/UseCase/JudgeRound/`; T12 (#17, part #460), `src/Core/Review/UseCase/JudgeRound/LogFailedJudging.php` |
| `ResetInterface` and `kernel.reset` | every stateful service resets between requests in worker mode and between messages in consumers; a PHPStan rule enforces it | in progress | T36 (#67), `config/gates/src/PhpStan/StatefulServiceResetRule.php`; T13 (#18), `src/Plugin/LocalGitForge/UseCase/ReviewChange/CleanupOnStop.php`; T29 (#29) planned |
| Scheduler | the watcher tick (`#[AsSchedule]`, one `RecurringMessage` per project, every minute); usage probes, deadlines and the weekly branch cleanup are planned | in progress | T38 (#490), `src/Core/Watcher/UseCase/WatchPullRequests/WatcherSchedule.php`; T14 (#19), T30 (#24) |
| Workflow | state machine of a review round and of each reviewer, with its Graphviz diagram; a completed-transition listener (`AsCompletedListener`) queues the judge of a completed round | in progress | T9 (#14, part #240), `config/packages/workflow.php`, `src/Core/Review/Domain/`, `docs/architecture/review-round.dot`; T12 (#17, part #446), `src/Core/Review/UseCase/JudgeRound/QueueJudgingOfCompletedRound.php` |
| Twig (`symfony/twig-bundle`) | server-rendered dashboard pages, auto-escaped, with no script on them | in progress | T20 (#26, parts #511, #512 and #513), `src/Core/Dashboard/`, `templates/dashboard/` |
| Lock (Semaphore) | caps parallel calls per vendor account, held on the provider that answers the review, a substitute included (file locks, since the other store needs Redis) | in progress | T9 (#14, part #241), T14 (#19, part #415), `src/Plugin/SymfonyAiVendor/Infrastructure/VendorAccountLimit.php`, `src/Plugin/SymfonyAiVendor/Infrastructure/SymfonyAiVendor.php` |
| Lock | one listing of a project's open pull requests at a time, and one look at a pull request at a time from the stale check to the round start, both tried without waiting | in progress | T38 (#488, #490), `src/Core/Watcher/UseCase/FindChangedPullRequests/FindChangedPullRequests.php`, `src/Core/Watcher/UseCase/WatchPullRequests/HeadLocks.php` |
| RateLimiter | per-account cooldown windows aligned to the vendor's reset: a `fixed_window` of one call anchored (`anchor_at`) at the reset, in a cache pool every worker shares, with Lock around each window | in progress | T14 (#19, part #414), `src/Core/Ai/Infrastructure/AccountCooldownWindows.php`, `config/packages/cache.yaml` |
| Webhook and RemoteEvent | a GitHub `RequestParser` that checks the signature and turns events into RemoteEvents (optional, last) | planned | T32 (#60) |
| HttpClient | `RetryableHttpClient` and scoped clients for GitHub. Built: the installation token exchange through `HttpClientInterface`; the pull request reads and the review post through the scoped client `github.client` with `retry_failed` (`RetryableHttpClient`), the post with the per-request `max_retries: 0` so it is sent once; all tested with `MockHttpClient` | in progress | T18 (#23, #540, #541); T37 (#485), `config/packages/framework.yaml`, `src/Plugin/GitHubForge/Infrastructure/GitHubForge.php`, `src/Plugin/GitHubForge/Infrastructure/InstallationTokenMinter.php` |
| Mercure and Turbo (Symfony UX) | Turbo Streams over FrankenPHP's built-in hub | planned | T21 (#27) |
| Console | 8.1 input mapping; `CommandTester` assertions (`assertCommandIsSuccessful()`); signal handling (`SignalableCommandInterface`) removes the review snapshot on SIGINT and SIGTERM | in progress | T2 (#7, part #47), `src/Core/Ledger/UseCase/ReadEvents/ReadEventsCommand.php`; T6 (#11, part #59), `src/Plugin/LocalGitForge/UseCase/SummarizeChange/SummarizeChangeCommand.php`; T13 (#18), `src/Plugin/LocalGitForge/UseCase/ReviewChange/ReviewChangeCommand.php`, `tests/Plugin/LocalGitForge/ReviewChangeCommandTest.php` |
| Uid | UUIDv7 ids for ledger events and findings | in progress | T2 (#7, part #46), `src/Core/Ledger/Infrastructure/DoctrineLedger.php` |
| DependencyInjection `#[AutowireCallable]` | the reviewer gets its per-checkout toolbox as a closure over `ReviewerToolboxFactory::create()` | in progress | T7 (#12), `src/Core/Review/Infrastructure/ReviewerAgent.php` |
| DependencyInjection service decoration (`decoration_on_invalid: ignore`, `#[AutowireDecorated]`) | the shadow guard decorates whichever forge writes (the GitHub forge), and drops out while none does | in progress | T41 (#524), `src/Core/Shadow/Infrastructure/ShadowGuardedForgeWrites.php` |
| DependencyInjection env var processor | `postgresql_server_version` gives a PostgreSQL `DATABASE_URL` a server version and leaves other engines to detect theirs | in progress | T3 (#8), `src/Core/Platform/Infrastructure/PostgreSqlServerVersion.php` |
| Clock | time only through `ClockInterface`, enforced by a gate | done | `config/gates/phpstan.neon` |
| Validator | checks typed verdicts from structured output | in progress | T1 (#5), `src/Core/Review/Contract/VerdictParser.php`, `src/Core/Review/Contract/Verdict.php`, `src/Plugin/SymfonyAiVendor/Infrastructure/SymfonyAiVendor.php`; T5 (#10, part #98) |
| Serializer (`ALLOW_EXTRA_ATTRIBUTES`, `REQUIRE_ALL_PROPERTIES`, `JsonDecode::ASSOCIATIVE`) | maps a verdict strictly: no unknown and no left-out fields, no object as a list | in progress | T1 (#5), `src/Core/Review/Infrastructure/StrictVerdictConverter.php` |
| Security | a voter decides which MCP tools may write; the `access_token` authenticator checks the owner's bearer token and, on the review server, a round's signed credential; the CSRF token manager guards the dashboard's start button (`CsrfTokenManagerInterface`, `csrf_token()` in Twig) | in progress | T15 (#20, parts #508 and #510), `src/Core/Mcp/Infrastructure/ToolAccessVoter.php`, `src/Core/Mcp/Infrastructure/OwnerTokenHandler.php`; T20 (#26, part #513), `src/Core/Dashboard/UseCase/StartReview/StartReviewController.php`; T16 (#21, part #578), `src/Core/Review/UseCase/ServeReviewTools/ReviewCredential.php`, `config/packages/security.yaml` |
| Process | the local git forge; every git call hardened, in the forge and in the gates; one PHPUnit or script run per upstream reproduction, with a timeout; the gitleaks scan of a review text before the GitHub forge posts it, the text on standard input | in progress | T6 (#11, part #58), `src/Plugin/LocalGitForge/Infrastructure/LocalGitForge.php`; T6 (#11, part #124), `src/Core/Git/Contract/HardenedGit.php`, `config/gates/src/HardenedGitCall.php`; T26 (#33), `src/Core/Upstream/Infrastructure/ReproductionRunner.php`; T18 (#23, #541), `src/Plugin/GitHubForge/Infrastructure/SecretScan.php` |
| Runtime: FrankenPHP worker mode | decided: one booted kernel for the HTTP side through `FrankenPhpWorkerRunner` | done | decision 17 in `docs/design/06-decisions.md` |
| Runtime: worker mode in the image, no state leaks | the image runs worker mode; a test proves nothing leaks between requests | planned | T22 (#28), T29 (#29) |

## FrankenPHP

| Feature | How Intakt uses it | Status | Where |
|---|---|---|---|
| Worker mode | one booted kernel for the HTTP side through Symfony Runtime's `FrankenPhpWorkerRunner` | done | decision 17 in `docs/design/06-decisions.md` |
| `dunglas/frankenphp` 1.13.4 or later, PHP 8.5 ZTS, pinned by digest | the base image, with the 1.13.2 and 1.13.4 worker-mode leak fixes | planned | T22 (#28) |
| Named workers with `match`, `num`, `max_threads` and `max_wait_time` | an `app` worker and a separate `mcp` worker, so streamed MCP responses can't starve the dashboard | planned | T22 (#28), T15 (#20) |
| Worker loop limit (`FRANKENPHP_LOOP_MAX`) | workers restart after a set number of requests; a terminate-time memory check ends the loop cleanly | planned | T22 (#28) |
| Restart on crash with backoff (`max_consecutive_failures`), graceful shutdown | defaults kept, beside a Docker restart policy | planned | T22 (#28) |
| Built-in Mercure hub with `publisher` and `issuer` keys | live updates; symfony/mercure mints the tokens; the hub is excluded from `encode` | planned | T21 (#27) |
| `mercure_publish()` | web-side publishing, checked at runtime; consumers publish through the hub endpoint | planned | T21 (#27) |
| Mercure `bolt` transport | event history, so a reconnecting client catches up with `Last-Event-ID` | planned | T21 (#27) |
| Prometheus metrics, `/frankenphp/threads`, `mercure_health` | the container health check and an infrastructure row on the dashboard | planned | T22 (#28), T34 (#63) |
| Admin API on localhost | never reachable from outside the container | planned | T22 (#28) |
| `FRANKENPHP_RESET_KERNEL=1` | the leak test runs with and without it; the image ships without it | planned | T29 (#29) |
| `watch` and hot reload | a development compose override | planned | T35 (#64) |

## Tooling

| Feature | How Intakt uses it | Status | Where |
|---|---|---|---|
| Quality gates | `bin/check` runs every gate locally and in CI | done | `bin/check`, `config/gates/` |
| PHPUnit 13 | test runner, failing on warnings, deprecations and mocks without expectations | done | `phpunit.dist.xml`, `phpunit.gates.xml` |
| `intakt:upstream:run` | runs the `upstream/` reproductions against the pinned versions | in progress | T26 (#33), `src/Core/Upstream/` |
| `intakt:change:summarize` | prints the change summary of a branch of a local repository | in progress | T6 (#11, part #59), `src/Plugin/LocalGitForge/UseCase/SummarizeChange/` |
| `intakt:verdict:identity` | prints the verdict identity of a branch of a local repository and whether a recorded one still counts | in progress | T11 (#16), `src/Plugin/LocalGitForge/UseCase/CheckVerdictIdentity/` |
| `intakt:review` | reviews a branch of a local repository and prints the outcome, the act list and the tokens, or JSON | in progress | T13 (#18), `src/Plugin/LocalGitForge/UseCase/ReviewChange/` |
| `bin/instance` and `bin/probe` | offline boot gate and reviewer probes | done | T31 (#6), `bin/instance`, `bin/probe` |

## Found in the beta

Each entry is an isolated PHPUnit reproduction under `upstream/<slug>/` at pinned versions. The owner approves each report before it is filed.

| Probe | Status | Reproduction | Upstream issue |
|---|---|---|---|
| Failover with errors that surface only when the result is read (a 429 in a `DeferredResult`); two platforms of the same class sharing one rate-limit key | planned | T25 (#32) | |
| Streaming with a tool call and a stream cut mid-text: execution fails, usage counted once, the tool runs once | planned | T25 (#32) | |
| Worker-mode state: two models and two MCP sessions in one kernel, flat memory over 1,000 calls | planned | T28 (#35), T29 (#29) | |
| Structured output with enums and nested lists per provider, a stream truncated mid-array | planned | T28 (#35) | |
| MCP: initialize with protocol 2025-11-25 and an unknown version, empty parameters as `{}` (done in `tests/Core/Mcp/Contract/`, T15, #508) | in progress | T15 (#20) | |
| MCP: three parallel `tools/call` on one session, with and without `session.lock`, protocol 2025-11-25, empty parameters as `{}` | planned | T28 (#35) | |
| FrankenPHP: `messenger:consume` under `frankenphp php-cli` on 1.13.4 stops cleanly on SIGTERM; a Symfony reproduction if it still crashes | planned | T28 (#35) | php/frankenphp#2479 (existing) |
| Reset in worker mode: Symfony AI services with mutable state but no `ResetInterface` (first candidate: the Platform `Provider` keeps `$resolvedModel`), found by running one kernel for two different requests | planned | T29 (#29) | |

## PHP 8.5 in use

- `#[NoDiscard]` on the instance status: `bin/instance` (done); on `Forge::hasHeadMoved()`: `src/Plugin/Forge/Contract/Forge.php` (done, T6).
- The pipe operator for turning `git diff` output into changed files: `src/Plugin/LocalGitForge/Infrastructure/NameStatusParser.php` (done, T6).
- `#[NoDiscard]` on the parse result: `src/Core/Review/Contract/VerdictParser.php` (in progress, T1).
- `clone()` with overrides in the verdict test builder: `tests/Core/Review/Contract/VerdictDocument.php` (in progress, T1).
- Closures in constant expressions for the built-in quorum policies, and `#[NoDiscard]` on `QuorumPolicy::isMet()`: `src/Core/Review/Contract/QuorumPolicy.php` (in progress, T8 (#13, part #222)).
- `array_first()` and `array_last()` for the first and latest round of a finding: `src/Core/Review/Domain/TrackedFinding.php` (in progress, T10 (#15, part #410)).
- `Uri\Rfc3986\Uri` for the pull request URL that the reads and the review post go to: `src/Plugin/GitHubForge/Infrastructure/PullRequestAddress.php` (in progress, T18 (#23, parts #540, #541)).
- Planned: the pipe operator (T5, T28, T30), `Uri\Rfc3986\Uri` (T19).
