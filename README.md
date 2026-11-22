<h1 align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="docs/assets/intakt-lockup-dark.svg">
    <img src="docs/assets/intakt-lockup.svg" alt="Intakt" width="220">
  </picture>
</h1>

[![ci](https://github.com/shinyredapples/intakt/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/shinyredapples/intakt/actions/workflows/ci.yml)

Your coding agent says it is done. Intakt tells you whether to believe it.

Reviewers on models from different AI labs review the change, and a judge marks every finding against the task. Start a review from the dashboard, or let your own agent run the loop over MCP until the verdict is clean. On the [review bench](#review-bench), a panel on Claude and GPT caught all 5 planted defects in each of 3 runs (15 of 15, measured 2026-11-21); on low-cost open models it caught 11 of 15.

Along the way, building Intakt led to [12 fixes (11 pull requests) merged upstream](#found-and-fixed-upstream) in Symfony, Symfony AI and Doctrine.

<!-- VIDEO THUMBNAIL: put the linked thumbnail of the demo video here once it is uploaded (#3142). -->

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/assets/dashboard-review-dark.png">
  <img src="docs/assets/dashboard-review.png" alt="The dashboard's review page for the sample change: the verdict card first, Changes requested with three things to fix, each with its priority and file and line, then what the change does and the tokens it took" width="760" height="475">
</picture>

PHP 8.5, Symfony AI · [Status](#status): what works today and what is planned · [Guides](docs/guide/README.md) · [Showcase](docs/showcase/README.md) · [For judges](docs/judges.md) · [Hackathon](docs/hackathon.md) · [Releases](https://github.com/shinyredapples/intakt/releases)

## Five-minute start

```
git clone https://github.com/shinyredapples/intakt.git && cd intakt
docker compose up -d --wait
```

No key? Open http://localhost:7781/demo (or **Watch a recorded review** on http://localhost:7781/setup) and press **Play the demo run**: the recording of a real review of the sample change plays onto the dashboard without calling any model ([details](docs/user/README.md#playing-the-demo-run-in-the-dashboard)).

With your agent: the clone's `.mcp.json` connects Claude Code. The review is live, so it needs a key first (see *With a key* below). Run `export INTAKT_MCP_TOKEN=<token from the first-run page>`, start `claude` in the clone, approve the `intakt` MCP server when Claude Code asks, install the [skill](skills/intakt-review/README.md) and say *intakt review the demo* ([details](docs/user/mcp.md)).

With a key: follow **Run it live with your key** on http://localhost:7781/setup: paste one provider key or Hugging Face token in step 1, run `docker compose restart intakt`, then press **Start the first review** ([Hugging Face details](docs/user/README.md#running-the-demo-on-a-hugging-face-token)). Or from the terminal: `docker compose exec intakt php bin/console intakt:demo` prints the verdict ([read it](docs/user/README.md#reading-a-verdict)). Calls are billed to the key's owner. The [demo walkthrough](docs/guide/demo-walkthrough.md) retells the run in plain words; [the review flow](docs/architecture/review-flow.md) draws it inside.

### Timed run

Measured on 2026-11-17 on an older 4-core desktop with 16 GB RAM and Docker 29: ten consecutive runs of the five-minute path, each from a fresh `git archive` of main, with a Hugging Face token in `.env.local` and the terminal path `docker compose exec intakt php bin/console intakt:demo`.

| Measure | Median | Range |
|---|---|---|
| Warm start, `docker compose up -d --wait` (image already built) | 13.5 s | 10 to 22 s |
| Demo review, `intakt:demo` to verdict | 42 s | 13 to 101 s |

Both reviewers answered in all ten runs, and all ten ended with the verdict `changes` on the sample change. A cold start, with the image removed and Docker's build cache kept, took a 42 s build and a 30 s start (72 s), then a 44 s review.

What works without Docker or a provider key:

```
composer install
bin/check            # the quality gates every change passes (not the journeys), one JSON line per gate
bin/check --journeys # the user journeys, each on a fresh install, run separately
bin/console about    # the app boots on SQLite
```

What the journeys cover and how to run one: [Checking the whole product](docs/guide/checking-the-whole-product.md).

### Review bench

The review bench reviews eight small changes of the sample shop, five with one planted defect each and three without any, and counts what each reviewer caught, missed and raised in error. **It calls your paid models**, so it runs only when you ask, never in `bin/check` or CI. With a running install, this gave the results below:

```
docker compose exec intakt php bin/console intakt:bench --project=sample --repeat=3
```

| Reviewer | Defects found (measured) | False alarms (measured) | Tokens per change (measured) |
| --- | --- | --- | --- |
| Panel on Claude and GPT (`correctness` on Claude `opus`, `security` on GPT `gpt-6.2-sol`), 2026-11-21 | 15 of 15 | 3 | about 75,600 |
| Claude `opus` alone | 12 of 15 | 0 | about 13,250 |
| GPT `gpt-6.2-sol` alone | 15 of 15 | 3 | about 62,400 |
| Open-model panel (`correctness` and `security`, Hugging Face), 2026-11-16 | 11 of 15 | 2 | about 6,800 |
| `correctness` alone (open model) | 10 of 15 (1 harness failure left out, on a change without a defect) | 2 | about 2,460 |
| `security` alone (open model) | 8 of 15 | 0 | about 4,350 |

Each change ran 3 times. In the open-model run seven of the eight changes varied between the three runs (in the Claude and GPT run none did): a single run's result for one change is partly luck, so your own run will not match these figures exactly.

Date of the run: 2026-11-21. Claude and GPT run: subscription CLIs (`claude_code` model `opus`, `codex` model `gpt-6.2-sol` with effort high, judge on Claude `haiku`), 24 rounds, all three runs agreeing on every change; the panel's figures are the union of its two reviewers' findings, and all three false alarms came from the GPT reviewer. Open-model run: 2026-11-16. Models (the demo's Hugging Face mapping): `correctness` (tier strong) `moonshotai/Kimi-K3:deepinfra`, `security` (tier standard) `openai/gpt-oss-120b:novita`. What a run costs and how to read these figures: [the review bench](docs/user/README.md#the-review-bench) in the user guide.

## Found and fixed upstream

One row per reproduction in `upstream/`, merged first. *Intakt's* marks a pull request or issue this project sent; *fixed upstream* marks a bug Intakt reproduced and others fixed; *already reported* marks an issue others filed first.

| Project | Bug | Upstream |
| --- | --- | --- |
| symfony/ai | [SQLite store: a quote breaks FTS5](upstream/ai-sqlite-store-unescaped-fts-quote/) | [#2874](https://github.com/symfony/ai/pull/2874) Intakt's, merged |
| symfony/ai | [Claude Code: tools the CLI ran come back as tool calls](upstream/ai-claude-code-platform-cli-tool-calls/) | [#2875](https://github.com/symfony/ai/pull/2875) Intakt's, merged |
| symfony/ai | [Codex: tools the CLI ran come back as tool calls](upstream/ai-codex-platform-cli-tool-calls/) | [#2875](https://github.com/symfony/ai/pull/2875) Intakt's, merged |
| symfony/ai | [`#[Positive]` gets a boolean `exclusiveMinimum`](upstream/ai-platform-positive-boolean-exclusive-minimum/) | [#2888](https://github.com/symfony/ai/pull/2888) Intakt's, merged |
| Doctrine | [PostgreSQL without `server_version` fails](upstream/doctrine-bundle-postgresql-empty-server-version/) | [DoctrineBundle#2335](https://github.com/doctrine/DoctrineBundle/pull/2335) Intakt's, merged |
| symfony/symfony | [NaN skips config validation](upstream/config-nan-skips-validation/) | [#67911](https://github.com/symfony/symfony/pull/67911) Intakt's, merged |
| symfony/symfony | [`messenger:consume` ignores an early SIGTERM](upstream/messenger-consume-ignores-early-sigterm/) | [#67912](https://github.com/symfony/symfony/pull/67912) Intakt's, merged |
| symfony/symfony | [Serializer list accepts any object](upstream/serializer-object-collection-accepts-any-object/) | [#67913](https://github.com/symfony/symfony/pull/67913) Intakt's, merged |
| symfony/ai | [Codex: a cut turn reads as complete](upstream/ai-codex-platform-cut-off-stream/) | [#2889](https://github.com/symfony/ai/pull/2889) Intakt's, merged |
| symfony/ai | [Codex: earlier user messages dropped](upstream/ai-codex-platform-last-user-message/) | [#2891](https://github.com/symfony/ai/pull/2891) Intakt's, merged |
| symfony/ai | [Claude Code: cache token split lost](upstream/ai-claude-code-platform-cache-token-split/) | [#2963](https://github.com/symfony/ai/pull/2963) Intakt's, merged |
| symfony/ai | [`Provider` keeps a resolved model](upstream/ai-platform-provider-keeps-resolved-model/) | [#2851](https://github.com/symfony/ai/pull/2851) fixed upstream, merged |
| symfony/ai | [Failover retries a failed platform](upstream/ai-failover-platform-retries-a-failed-platform/) | [#2890](https://github.com/symfony/ai/pull/2890) Intakt's, open |
| symfony/ai | [Typed answer of a multi-part reply missed](upstream/ai-structured-output-multi-part-answers/) | [#2945](https://github.com/symfony/ai/issues/2945) Intakt's, reported |
| symfony/ai | [Failover misses a deferred 429](upstream/ai-failover-platform-deferred-rate-limit/) | [#2944](https://github.com/symfony/ai/issues/2944) Intakt's, reported |
| symfony/ai | [Claude Code: response format passed as `Array`](upstream/ai-claude-code-platform-structured-output/) | [#2962](https://github.com/symfony/ai/issues/2962) Intakt's, reported |
| symfony/ai | [Failed streamed tool run reports no usage](upstream/ai-agent-streamed-tool-call-cut-stream/) | [comment on #2617](https://github.com/symfony/ai/pull/2617#issuecomment-6876304595) Intakt's, reported |
| PHPStan | [Readonly type kept after `clone with`](upstream/phpstan-clone-with-readonly-constructor-type/) | [phpstan#14838](https://github.com/phpstan/phpstan/issues/14838) already reported |
| FrankenPHP | [`php-cli` crashes on SIGTERM](upstream/frankenphp-messenger-consume-sigterm/) | [frankenphp#2479](https://github.com/php/frankenphp/issues/2479) already reported, still fails on 1.13.6 (checked 2026-11-21) |
| Doctrine | [A refused commit leaves the transaction open](upstream/dbal-failed-commit-leaves-transaction-open/) | [#7633](https://github.com/doctrine/dbal/pull/7633) Intakt's, open |
| MCP SDK | [Three parallel `tools/call` on one session](upstream/mcp-parallel-tools-call-one-session/) | passes, nothing to file |
| symfony/ai | [Worker-mode state of two models and two MCP sessions](upstream/ai-worker-mode-state/) | passes, nothing to file |
| symfony/ai | [Structured output with enums and nested lists](upstream/ai-structured-output-enums-nested-lists/) | passes, nothing to file |
| symfony/ai | [Generic bridge: check whether a complete stream is read as cut](upstream/ai-generic-platform-cut-stream/) | passes, nothing to file |
| symfony/ai | [AI Mate: a symlinked `vendor` moves the root](upstream/ai-mate-symlinked-vendor-root/) | not filed: Composer's autoloaders do not support it either |
| symfony/ai | [Claude Code: a cut run without streaming](upstream/ai-claude-code-platform-cut-off-stream/) | not filed: the exceptions raised are accurate |
| symfony/ai | [Codex: usage of a turn without an answer lost](upstream/ai-codex-platform-usage-without-answer/) | not filed: unreachable through a `Platform` |

How each folder was checked and filed: [`upstream/`](upstream/README.md).

<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="docs/assets/team-dark.webp" width="760" height="116">
    <img src="docs/assets/team-light.webp" alt="The Intakt team: the octopus conductor with a baton, then the Tech lead, Developer, Code reviewer, Judge, Security engineer, Designer, AI engineer and Maintainer, each drawn as an animal with its tool" width="760" height="113" loading="lazy">
  </picture>
  <br>
  The octopus conducts: Tech lead, Developer, Code reviewer, Judge, Security engineer, Designer, AI engineer and Maintainer, in that order.
</p>

## The first release: the review loop

Built for the [Symfony AI & MCP Hackathon](docs/spec.md) (deadline 2026-11-22):

- Point Intakt at a change, a branch of a local repository. A configurable panel of reviewers, each a provider, a model tier and a prompt through Symfony AI's Platform, returns structured JSON verdicts.
- Findings keep their ids across rounds, enforced in code, so reviewers cannot move the goalposts. A judge step tells the builder what to act on, and only a P3 finding can be deferred or rejected. The outcome and the act list agree: a round sent back as `changes` always names a finding to act on, and a reviewer whose findings are all P3s the judge deferred or rejected counts as approving, with follow-ups.
- Agents start reviews and read findings through an MCP server.
- A small live dashboard shows each round as it streams.
- Every figure says what kind it is: token counts the provider reported are `measured`, rounds and findings from the ledger are `counted`, and a cost from your price list is `estimated`, with the date of the prices. Figures of different kinds are never added into one total.
- `docker compose up` with a sample repository and your API keys (or a Hugging Face token) runs it in five minutes.

Development continues after the hackathon: [the hackathon page](docs/hackathon.md) says what was planned, what was achieved and what comes next.

## Status

<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="docs/assets/roadmap-dark.svg" width="420" height="245">
    <img src="docs/assets/roadmap-light.svg" alt="Intakt roadmap: started 24 Oct by a custom build loop; done: M0 Unblock, M1 Review core, M2 Panel and rounds, M3 Agents and memory, M4 Live dashboard; we are here, with the octopus: M5 Demo, the hackathon release, 22 Nov; next: M6 Runs alongside (beside the build loop), M7 Builder half (Intakt builds as well as reviews), M8 Switch (Intakt builds itself)" width="420" height="245" loading="lazy">
  </picture>
</p>

**Works today:** the review loop and its foundation. A reviewer panel of any size, at least one reviewer (`config/intakt.yaml`) reviews a branch of a local repository (`intakt:review`, the dashboard button or the MCP tool `start_review`), a judge sorts the findings into the act list, and the dashboard shows each round live; the demo runs it on a sample repository with `docker compose up`. Under it, a Symfony 8.2 (8.2.0-BETA2, a beta until 8.2.0 is released) app on PHP 8.5.12 or later with SQLite, Symfony AI 1.0.0-BETA1 (Platform, Agent, Store, AI Bundle) and the MCP Bundle installed, the module layout with plugin contracts, the Anthropic, OpenAI and OpenAI-compatible providers with keys from the environment or Symfony secrets (a provider without its key is switched off with a message), and `bin/check`, which runs every quality gate CI runs: coding standards, PHPStan at max (with the service ids and parameter types of the compiled container), Rector, module boundaries, PHPUnit 13, `composer audit`, gitleaks on files and history, commit subjects, long dashes, the `docs/highlights.md` table lint, a row there for every `upstream/` reproduction and the service container lint.

**Planned:** reviewing a pull request on GitHub and posting the result there, rounds started by the loop itself, the catch-up review after a substitute, and everything outside the review loop, in the order of [the plan](docs/plan.md). The aim is that you give Intakt a spec, and AI agents turn it into small tasks, build each one and merge what passes review, spending only the budget you give them, asking you only when a person is needed, and acting as Intakt's own forge identity, never as you.

## How it is built

- **PHP 8.5 and Symfony 8.2 (a beta until 8.2.0 is released)**, with the latest stable tools, and Symfony AI used for every model call, agent and memory, on purpose, to find the beta's bugs early.
- **FrankenPHP worker mode** for the dashboard and the MCP server, with Mercure built in for live updates; Messenger consumers and the Scheduler (the watcher tick every minute, started in the image by its own consumer; usage probes, deadlines and the weekly cleanup are planned) run beside it. The local API stays planned.
- **No public resource API in v1.** The interfaces are the dashboard, the CLI, an optional forge webhook endpoint (forge changes are polled by default) and the MCP server.

## Documentation

- [For judges](docs/judges.md): from clone to a played review in a few minutes, and the code behind each promise.
- [Intakt in plain words](docs/guide/README.md): friendly guides with diagrams, from the big picture to troubleshooting.
- [Contributing](CONTRIBUTING.md): reporting bugs, suggesting features, sending pull requests, and [how they are handled](CONTRIBUTING.md#how-your-issue-or-pull-request-is-handled).
- [User guide](docs/user/README.md): for owners running Intakt.
- [Technical pages](docs/architecture/README.md): how it works, the gates, the module layout.
- [Design](docs/design/README.md): what Intakt does and why.
- [Releases](https://github.com/shinyredapples/intakt/releases): tagged versions and their notes; [how a release is cut](docs/releasing.md).
- [Why the AI can't wreck your project](docs/guide/why-the-ai-cant-wreck-your-project.md): the safety design in plain words.
- [Showcase](docs/showcase/README.md): this repository built by an AI loop, in figures and examples.
- [Highlights](https://shinyredapples.github.io/intakt/): every Symfony and Symfony AI feature Intakt uses or plans, with its status, published from main (built from [`docs/highlights.d/`](docs/highlights.d/)).
- [Journey](docs/journey.md): how Intakt got from an empty repository to the demo, with its milestones, decisions, capacity, CI, token use (measured) and issue flow (counted).
- [Spec](docs/spec.md) and [plan](docs/plan.md) of the first release.
- [AGENTS.md](AGENTS.md): the rules for anyone, person or agent, changing the code.

## License

MIT, see [LICENSE](LICENSE) and [NOTICE](NOTICE).
