<h1 align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="docs/assets/intakt-lockup-dark.svg">
    <img src="docs/assets/intakt-lockup.svg" alt="Intakt" width="220">
  </picture>
</h1>

Your coding agent says it is done. Intakt tells you whether to believe it.

A panel of reviewers on models from different makers reviews the change and a judge marks every finding against the task: every P1 and P2 finding whose quote checks out reaches the act list whatever the judge answers. Your own agent runs this loop over MCP until the verdict is clean. On the [review bench](#review-bench), the panel found 11 of 15 planted defects (measured, 2026-11-16).

<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="docs/assets/team-dark-1x.webp 1x, docs/assets/team-dark.webp 2x" width="760" height="116">
    <img src="docs/assets/team-light-1x.webp" srcset="docs/assets/team-light-1x.webp 1x, docs/assets/team-light.webp 2x" alt="The Intakt team: the octopus conductor with a baton, then the Tech lead, Developer, Code reviewer, Judge, Security engineer, Designer, AI engineer and Maintainer, each drawn as an animal with its tool" width="760" height="113" loading="lazy">
  </picture>
  <br>
  The octopus conducts: Tech lead, Developer, Code reviewer, Judge, Security engineer, Designer, AI engineer and Maintainer, in that order.
</p>

PHP 8.5, Symfony AI · [Status](#status): what works today and what is planned · [Guides](docs/guide/README.md) · [Showcase](docs/showcase/README.md) · [Releases](https://github.com/shinyredapples/intakt/releases)

## Five-minute start

```
git clone https://github.com/shinyredapples/intakt.git && cd intakt
docker compose up -d --wait
```

No key? Open http://localhost:7781/demo and press **Play the demo run**.

With your agent: `claude mcp add --transport http intakt http://localhost:7781/mcp/owner --header "Authorization: Bearer <the token>"` (token: first-run page step 4, or `intakt:demo`), install the [skill](skills/intakt-review/README.md) and say *intakt review the demo*.

The demo run plays the shipped recording of a real review of the sample change onto the dashboard without calling any model, with a caption on every page that names each stage up to the judge's act list ([details](docs/user/README.md#playing-the-demo-run-in-the-dashboard)).

The demo reviews a sample change with two reviewers ([how long it takes](#timed-run)): open http://localhost:7781. A new install opens on a first-run page with two choices: **watch a recorded real review now**, with no key, or **paste one provider key or Hugging Face token and run the demo review**, which opens its live review page. Calls are billed to the key's or token's owner; a Hugging Face token alone puts the reviewers on two makers ([Hugging Face details](docs/user/README.md#running-the-demo-on-a-hugging-face-token)).

From the terminal: `docker compose exec intakt php bin/console intakt:demo` runs the same demo review and prints its verdict ([read it](docs/user/README.md#reading-a-verdict)). The [demo walkthrough](docs/guide/demo-walkthrough.md) retells it in plain words; [the review flow](docs/architecture/review-flow.md) draws it inside.

MCP: your agent loops, fixes and reports ([details](docs/user/mcp.md)).

<details>
<summary>Screenshot: the dashboard's review page for the sample change</summary>

![The dashboard's review page for the sample feat/cart change: two rounds with two reviewers each, their verdicts, the findings with the judge's call on each, and the tokens per round, measured](docs/assets/dashboard-review.png)

</details>

### Timed run

On arm64 Linux, the `image-arm64` CI workflow builds and first-starts the image on every push to main and prints both timings. Docker Desktop on Apple Silicon runs the arm64 image.

Measured on 2026-11-17 on a 4-core Intel i7-4790K with 16 GB RAM, Ubuntu 26.10 and Docker 29: ten consecutive runs of the five-minute path, each from a fresh `git archive` of main, with a Hugging Face token in `.env.local` and the terminal path `docker compose exec intakt php bin/console intakt:demo`.

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

The review bench measures how well the panel finds defects: it reviews eight small changes of the sample shop, five with one planted defect each and three without any, and counts what each reviewer caught, missed and raised in error. **It calls your paid models**, so it runs only when you ask; nothing in `bin/check` or CI runs it. With the demo running, this runs the bench that gave the result below:

```
docker compose exec intakt php bin/console intakt:bench --project=sample --repeat=3
```

| Reviewer | Defects found (measured) | False alarms (measured) | Tokens per change (measured) |
| --- | --- | --- | --- |
| Panel (`correctness` and `security`) | 11 of 15 | 2 | about 6,800 |
| `correctness` alone | 10 of 15 (1 harness failure left out, on a change without a defect) | 2 | about 2,460 |
| `security` alone | 8 of 15 | 0 | about 4,350 |

Seven of the eight changes varied between the three runs, so your own run will not match these figures exactly.

Date of the run: 2026-11-16. Models (the demo's Hugging Face mapping): `correctness` (tier strong) `moonshotai/Kimi-K3:deepinfra`, `security` (tier standard) `openai/gpt-oss-120b:novita`. Command: `intakt:bench --project=sample --repeat=3`, as above. What a run costs and how to read these figures: [the review bench](docs/user/README.md#the-review-bench) in the user guide.

## Found and fixed upstream

Bugs Intakt's reproductions found in the libraries it uses, one row per `upstream/` folder that went upstream, merged first:

| Project | Bug | Upstream |
| --- | --- | --- |
| symfony/symfony | [Config: a NaN value skips the type check and the validation closures](upstream/config-nan-skips-validation/) | [#67911](https://github.com/symfony/symfony/pull/67911): merged, in v8.2.0-BETA2 |
| symfony/symfony | [Messenger: `messenger:consume` ignores a SIGTERM that arrives before its worker exists](upstream/messenger-consume-ignores-early-sigterm/) | [#67912](https://github.com/symfony/symfony/pull/67912): merged, in v8.2.0-BETA2 |
| symfony/symfony | [Serializer: a list of objects accepts any object and ends in a `TypeError`](upstream/serializer-object-collection-accepts-any-object/) | [#67913](https://github.com/symfony/symfony/pull/67913): merged, in v8.2.0-BETA2 |
| Doctrine | [DoctrineBundle: a PostgreSQL connection without `server_version` fails before it connects](upstream/doctrine-bundle-postgresql-empty-server-version/) | [doctrine/DoctrineBundle#2335](https://github.com/doctrine/DoctrineBundle/pull/2335): merged |
| symfony/ai | [SQLite store: a double quote in the query text breaks the FTS5 query](upstream/ai-sqlite-store-unescaped-fts-quote/) | [#2874](https://github.com/symfony/ai/pull/2874): merged |
| symfony/ai | [Claude Code bridge: tools the CLI already ran are not returned as tool calls](upstream/ai-claude-code-platform-cli-tool-calls/) | [#2875](https://github.com/symfony/ai/pull/2875): merged |
| symfony/ai | [Codex bridge: MCP tools the CLI already called are not returned as tool calls](upstream/ai-codex-platform-cli-tool-calls/) | [#2875](https://github.com/symfony/ai/pull/2875): merged |
| symfony/ai | [Platform: `#[Positive]` is described with a boolean `exclusiveMinimum`](upstream/ai-platform-positive-boolean-exclusive-minimum/) | [#2888](https://github.com/symfony/ai/pull/2888): merged |
| symfony/ai | [Codex bridge: a turn without `turn.completed` is returned as a complete answer](upstream/ai-codex-platform-cut-off-stream/) | [#2889](https://github.com/symfony/ai/pull/2889): merged |
| symfony/ai | [Codex bridge: every user message but the last is dropped](upstream/ai-codex-platform-last-user-message/) | [#2891](https://github.com/symfony/ai/pull/2891): merged |
| symfony/ai | [Platform: a `Provider` keeps its resolved model and sends an earlier request's model options](upstream/ai-platform-provider-keeps-resolved-model/) | [#2851](https://github.com/symfony/ai/pull/2851): merged |
| symfony/ai | [`FailoverPlatform` calls a failed platform again, and every platform shares one limiter](upstream/ai-failover-platform-retries-a-failed-platform/) | [#2890](https://github.com/symfony/ai/pull/2890): open |
| symfony/ai | [Claude Code bridge: cache creation and cache read tokens are added together](upstream/ai-claude-code-platform-cache-token-split/) | [#2963](https://github.com/symfony/ai/pull/2963): open |
| symfony/ai | [Structured output: the typed answer of a model that thinks or calls tools is missed](upstream/ai-structured-output-multi-part-answers/) | [#2945](https://github.com/symfony/ai/issues/2945): reported |
| symfony/ai | [Failover: a 429 that surfaces only when the result is read](upstream/ai-failover-platform-deferred-rate-limit/) | [#2944](https://github.com/symfony/ai/issues/2944): reported |
| symfony/ai | [Claude Code bridge: a response format becomes `--response-format Array`](upstream/ai-claude-code-platform-structured-output/) | [#2962](https://github.com/symfony/ai/issues/2962): reported |
| symfony/ai | [Agent: a failed streamed execution with a tool call reports no usage](upstream/ai-agent-streamed-tool-call-cut-stream/) | [comment on #2617](https://github.com/symfony/ai/pull/2617#issuecomment-6876304595): reported |
| PHPStan | [A readonly property set to `null` in the constructor still reads as `null` after `clone with`](upstream/phpstan-clone-with-readonly-constructor-type/) | [phpstan/phpstan#14838](https://github.com/phpstan/phpstan/issues/14838): reported, open |
| FrankenPHP | [`messenger:consume` under `frankenphp php-cli` crashes on SIGTERM](upstream/frankenphp-messenger-consume-sigterm/) | [php/frankenphp#2479](https://github.com/php/frankenphp/issues/2479): reported, open |

The other seven folders are probes that pass with nothing to file (among them the MCP SDK's parallel calls on one session) or reports not filed after the owner's review. How every reproduction is kept and checked: [`upstream/`](upstream/README.md).

## The first release: the review loop

Built for the [Symfony AI & MCP Hackathon](docs/spec.md) (deadline 2026-11-22):

- Point Intakt at a change, a branch of a local repository. A configurable panel of reviewers, each a provider, a model tier and a prompt through Symfony AI's Platform, returns structured JSON verdicts.
- Findings keep their ids across rounds, enforced in code, so reviewers cannot move the goalposts. A judge step tells the builder what to act on, and only a P3 finding can be deferred or rejected. The outcome and the act list agree: a round sent back as `changes` always names a finding to act on, and a reviewer whose findings are all P3s the judge deferred or rejected counts as approving, with follow-ups.
- Agents start reviews and read findings through an MCP server.
- A small live dashboard shows each round as it streams.
- Every figure says what kind it is: token counts the provider reported are `measured`, rounds and findings from the ledger are `counted`, and a cost from your price list is `estimated`, with the date of the prices. Figures of different kinds are never added into one total.
- `docker compose up` with a sample repository and your API keys (or a Hugging Face token) runs it in five minutes.

## Status

**Works today:** the review loop and its foundation. A reviewer panel of any size, at least one reviewer (`config/intakt.yaml`) reviews a branch of a local repository (`intakt:review`, the dashboard button or the MCP tool `start_review`), a judge sorts the findings into the act list, and the dashboard shows each round live; the demo runs it on a sample repository with `docker compose up`. Under it, a Symfony 8.2 (8.2.0-BETA2, a beta until 8.2.0 is released) app on PHP 8.5.12 or later with SQLite, Symfony AI 1.0.0-BETA1 (Platform, Agent, Store, AI Bundle) and the MCP Bundle installed, the module layout with plugin contracts, the Anthropic, OpenAI and OpenAI-compatible providers with keys from the environment or Symfony secrets (a provider without its key is switched off with a message), and `bin/check`, which runs every quality gate CI runs: coding standards, PHPStan at max (with the service ids and parameter types of the compiled container), Rector, module boundaries, PHPUnit 13, `composer audit`, gitleaks on files and history, commit subjects, long dashes, the `docs/highlights.md` table lint, a row there for every `upstream/` reproduction and the service container lint.

**Planned:** reviewing a pull request on GitHub and posting the result there, rounds started by the loop itself, the catch-up review after a substitute, and everything outside the review loop, in the order of [the plan](docs/plan.md). The aim is that you give Intakt a spec, and AI agents turn it into small tasks, build each one and merge what passes review, spending only the budget you give them, asking you only when a person is needed, and acting as Intakt's own forge identity, never as you.

## How it is built

- **PHP 8.5 and Symfony 8.2 (a beta until 8.2.0 is released)**, with the latest stable tools, and Symfony AI used for every model call, agent and memory, on purpose, to find the beta's bugs early.
- **FrankenPHP worker mode** for the dashboard and the MCP server, with Mercure built in for live updates; Messenger consumers and the Scheduler (the watcher tick every minute, started in the image by its own consumer; usage probes, deadlines and the weekly cleanup are planned) run beside it. The local API stays planned.
- **Steering by labels** (planned) on your forge: `intakt` to take a task, then `priority:`, `class:`, `model:`, `plan:` and `review:`; Intakt mirrors progress in `status:`.
- **No public resource API in v1.** The interfaces are the dashboard, the CLI, an optional forge webhook endpoint (forge changes are polled by default) and the MCP server.

## Documentation

- [Intakt in plain words](docs/guide/README.md): friendly guides with diagrams, from the big picture to troubleshooting.
- [Contributing](CONTRIBUTING.md): reporting bugs, suggesting features, sending pull requests.
- [How your issue or pull request is handled](CONTRIBUTING.md#how-your-issue-or-pull-request-is-handled): who decides what gets built, and why only labels from people with write access count.
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
