# Highlights: Symfony and Symfony AI in Intakt

Intakt runs on PHP 8.5, Symfony 8.1, Symfony AI 1.0.0-BETA1 and the PHP MCP SDK. This page lists every Symfony, Symfony AI and FrankenPHP feature Intakt uses or plans to use, and what it found in the beta. A change that starts or stops using a feature updates its row in the same change (see `AGENTS.md`).

Status is `planned`, `in progress` (an open pull request) or `done` (on main). **Where** names the plan task and its issue, and once built the main file. A row cites the issue, not the pull request: the issue links its pull requests, and a build cannot know its pull request number before the pull request exists.

## Symfony AI Platform

| Feature | How Intakt uses it | Status | Where |
|---|---|---|---|
| Several providers at once | Anthropic, OpenAI and an OpenAI-compatible endpoint in one install | in progress | T4 (#9, part #51), `config/packages/ai.yaml` |
| Structured output into typed objects (`asObject()`, Validator subscriber) | verdicts and judge results as PHP objects with enums and nested lists | in progress | T1 (#5), `src/Core/Review/Contract/VerdictParser.php`, `src/Core/Review/Contract/Verdict.php`; T5 (#10), T12 (#17) |
| Token usage (`getTokenUsages()`, `TokenUsage`, cached tokens, `remaining*`) | per-call cost in the ledger, remaining tokens into the budget planner | planned | T5 (#10) |
| Typed errors (`RateLimitExceededException::getRetryAfter()`, `RetryableExceptionInterface`) | cooldowns and retries without parsing text | planned | T5 (#10), T9 (#14), T14 (#19) |
| Anthropic prompt caching | later rounds of a review read cached tokens | planned | T7 (#12) |
| `FailoverPlatform` | provider failover inside Intakt's own wrapper | planned | T14 (#19) |
| Streaming | reviewer text shown live | planned | T21 (#27) |
| `MockPlatformFactory` with `ScriptedResponse` | offline tests of every model call | planned | T4 (#9), T5 (#10), T7 (#12) |
| `RecordingProvider` with `HttpCassette` | real responses recorded once, replayed offline with redaction | planned | T25 (#32), T28 (#35) |
| Claude Code and Codex platform bridges | a reviewer on a subscription login, `IncompleteStreamException` exercised | planned | T33 (#61) |

## Symfony AI Agent

| Feature | How Intakt uses it | Status | Where |
|---|---|---|---|
| Agent with tools and tool effect hints | one agent per reviewer role and for the judge, with read-only repository tools | planned | T7 (#12), T12 (#17) |
| `FaultTolerantToolbox` | a failing tool answers the model with an error instead of ending the run | planned | T7 (#12) |
| `ToolCallRequested` event | a listener denies tool arguments that escape the checkout | planned | T7 (#12) |
| Message store (`MessageStoreInterface`, Doctrine DBAL store) | the conversation of each round | planned | T7 (#12), T29 (#29) |
| Built-in MCP client tool (`mcp_server` in `ai.agent.<name>.tools`) | reviewers reach the per-review MCP server | planned | T16 (#21) |
| `MemoryInputProcessor` | the project memory briefing, with a size cap | planned | T17 (#22) |
| Streaming `Progress` and `Execution::cancel()` | live progress on the dashboard and a stop button | planned | T21 (#27) |

## Symfony AI Store

| Feature | How Intakt uses it | Status | Where |
|---|---|---|---|
| `DocumentIndexer` and `Retriever` | index resolved findings, retrieve them for the files under review | planned | T17 (#22) |
| `symfony/ai-sqlite-store` | embedded store on SQLite; it loads all vectors into PHP memory, which fits one project's memory | planned | T17 (#22) |
| In-memory store | retrieval tests | planned | T17 (#22) |
| Reranker | only when a configured provider supports reranking | planned | T17 (#22) |

## AI Bundle

| Feature | How Intakt uses it | Status | Where |
|---|---|---|---|
| Platform configuration, keys from Symfony secrets or the environment | providers configured per install; a missing key disables its provider | in progress | T4 (#51), `config/packages/ai.yaml` |
| `model_catalog` and a model router | capability tiers mapped to provider models per project | planned | T4 (#52) |
| `AiAssertionsTrait` (`assertToolCalled`, `assertPlatformCallCount`) | tests assert tool calls and the number of platform calls | planned | T7 (#12), T13 (#18) |

## MCP Bundle and MCP SDK

| Feature | How Intakt uses it | Status | Where |
|---|---|---|---|
| MCP Bundle server for the owner | agents start reviews and read verdicts and the act list | planned | T15 (#20) |
| MCP Bundle server per review | serves the read-only repository tools of the head under review | planned | T16 (#21) |
| `session.lock` | safe parallel calls on one session | planned | T15 (#20), T16 (#21) |
| `#[IsGrantedTool]` | tool authorization through Intakt's Security voter | planned | T15 (#20), T16 (#21) |
| Tool annotations (`readOnlyHint`, `destructiveHint`) | every tool states its effect | planned | T15 (#20), T16 (#21) |
| Progress notifications | long tools report progress | planned | T15 (#20), T16 (#21) |
| MCP SDK client | contract tests that call every tool | planned | T15 (#20) |

## Mate

| Feature | How Intakt uses it | Status | Where |
|---|---|---|---|
| Mate as a dev dependency | development diagnostics against the running app | planned | T27 (#34) |

## Symfony components

| Feature | How Intakt uses it | Status | Where |
|---|---|---|---|
| Messenger | command and query buses; a rate-limited reviewer retries after `getRetryAfter()` through `RecoverableExceptionInterface::getRetryDelay()`; the failure transport means `blocked` | planned | T9 (#14) |
| `ResetInterface` and `kernel.reset` | every stateful service resets between requests in worker mode and between messages in consumers; a PHPStan rule enforces it | planned | T36 (#67), T29 (#29) |
| Scheduler | usage probes, deadlines and the weekly branch cleanup | planned | T14 (#19), T30 (#24) |
| Workflow | state machine of a review round and of each reviewer, with its Graphviz diagram | planned | T9 (#14) |
| Lock (Semaphore) | caps parallel calls per vendor account | planned | T9 (#14) |
| RateLimiter | per-account cooldown windows aligned to the vendor's reset | planned | T14 (#19) |
| Webhook and RemoteEvent | a GitHub `RequestParser` that checks the signature and turns events into RemoteEvents | planned | T32 (#60) |
| HttpClient | `RetryableHttpClient` and scoped clients for GitHub | planned | T18 (#23) |
| Mercure and Turbo (Symfony UX) | Turbo Streams over FrankenPHP's built-in hub | planned | T21 (#27) |
| Console | 8.1 input mapping, `CommandTester` assertions | in progress | T13 (#18), T26 (#33), `src/Core/Upstream/UseCase/RunReproductions/RunReproductionsCommand.php` |
| Uid | UUIDv7 ids for ledger events and findings | planned | T2 (#7, part #46) |
| Clock | time only through `ClockInterface`, enforced by a gate | done | `config/gates/phpstan.neon` |
| Validator | checks typed verdicts from structured output | in progress | T1 (#5), `src/Core/Review/Contract/VerdictParser.php`, `src/Core/Review/Contract/Verdict.php`; T5 (#10) |
| Security | a voter decides which MCP tools may write | planned | T15 (#20), T16 (#21) |
| Process | the local git forge; one PHPUnit or script run per upstream reproduction, with a timeout | in progress | T6 (#11, part #58), `src/Plugin/LocalGitForge/Infrastructure/LocalGitForge.php`; T26 (#33), `src/Core/Upstream/Infrastructure/ReproductionRunner.php` |
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
| `bin/instance` and `bin/probe` | offline boot gate and reviewer probes | done | T31 (#6), `bin/instance`, `bin/probe` |

## Found in the beta

Each entry is an isolated PHPUnit reproduction under `upstream/<slug>/` at pinned versions. The owner approves each report before it is filed.

| Probe | Status | Reproduction | Upstream issue |
|---|---|---|---|
| Failover with errors that surface only when the result is read (a 429 in a `DeferredResult`); two platforms of the same class sharing one rate-limit key | planned | T25 (#32) | |
| Streaming with a tool call and a stream cut mid-text: execution fails, usage counted once, the tool runs once | planned | T25 (#32) | |
| Worker-mode state: two models and two MCP sessions in one kernel, flat memory over 1,000 calls | planned | T28 (#35), T29 (#29) | |
| Structured output with enums and nested lists per provider, a stream truncated mid-array | planned | T28 (#35) | |
| MCP: three parallel `tools/call` on one session, with and without `session.lock`, protocol 2025-11-25, empty parameters as `{}` | planned | T28 (#35) | |
| FrankenPHP: `messenger:consume` under `frankenphp php-cli` on 1.13.4 stops cleanly on SIGTERM; a Symfony reproduction if it still crashes | planned | T28 (#35) | php/frankenphp#2479 (existing) |
| Reset in worker mode: Symfony AI services with mutable state but no `ResetInterface` (first candidate: the Platform `Provider` keeps `$resolvedModel`), found by running one kernel for two different requests | planned | T29 (#29) | |

## PHP 8.5 in use

- `#[NoDiscard]` on the instance status: `bin/instance` (done); on `Forge::hasHeadMoved()`: `src/Plugin/Forge/Contract/Forge.php` (done, T6).
- The pipe operator for turning `git diff` output into changed files: `src/Plugin/LocalGitForge/Infrastructure/NameStatusParser.php` (done, T6).
- Planned: the pipe operator (T5, T28, T30), `clone()` with overrides (T1), `array_first()` and `array_last()` (T10), `Uri\Rfc3986\Uri` (T18, T19), closures in constant expressions (T8).
