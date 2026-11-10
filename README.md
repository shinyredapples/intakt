# Intakt

<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="docs/assets/intakt-lockup-dark.svg">
    <img src="docs/assets/intakt-lockup.svg" alt="intakt" width="420">
  </picture>
</p>

The aim: give it a spec, and AI agents plan, build, review and merge the work on your repository. PHP 8.5, Symfony AI. The review loop works today; the rest is planned, and [Status](#status) says which is which.

- **What it will do:** turn a spec into small tasks, have AI agents build each one, and merge what passes review (planned; the review step works today).
- **Why it is different:** a panel of reviewers on models of different makers returns structured verdicts, with findings that keep their ids across rounds, so reviewers cannot move the goalposts.
- **Why you will be able to trust it:** it will spend only the budget you give it, ask you only when a person is needed, and act as its own forge identity, never as you.

![The dashboard's review page for the sample feat/cart change: two rounds with two reviewers each, their verdicts, the findings with the judge's call on each, and the tokens per round](docs/assets/dashboard-review.png)

[Guides](docs/guide/README.md) · [Showcase](docs/showcase/README.md) · [Releases](https://github.com/shinyredapples/intakt/releases)

## Five-minute start

The demo reviews a sample pull request with two reviewers (its [timed run](#timed-run) is still waiting for the owner, so no timings are recorded yet):

```
git clone https://github.com/shinyredapples/intakt.git && cd intakt
cp .env.dist .env.local   # add a provider API key or a Hugging Face token, and the two secrets
docker compose up         # Intakt, its database and a sample repository
```

No paid Anthropic or OpenAI key? Set `HF_TOKEN` instead: create a fine-grained Hugging Face token with only "Make calls to Inference Providers" (the hackathon's credits cover its calls). Alone it puts the two reviewers on models of two different makers, `moonshotai/Kimi-K3:deepinfra` and `openai/gpt-oss-120b:novita`; beside `ANTHROPIC_API_KEY`, `correctness` stays on Anthropic and `security` runs on Hugging Face. The base URL is `https://router.huggingface.co`, without `/v1`, and each model names its router provider, because some router providers refuse tools together with structured output ([details](docs/user/README.md#running-the-demo-on-a-hugging-face-token)).

The panel's reviewers should run on models of different makers. Two makers through one Hugging Face token satisfy that; two separate providers (for example Anthropic and Hugging Face) are the stronger setup, because one token is one outage, one rate limit and one bill.

Then start the review with the command the log prints at the end of the first start, and open the dashboard at `http://localhost:7781` ([details](docs/user/README.md#the-demo-with-docker-compose)). The command exits with 0 (approve), 10 (changes) or 11 (blocked); [Reading a verdict](docs/user/README.md#reading-a-verdict) explains what it prints. To let your own agent start reviews, copy the line the first start prints after the review command (it holds the MCP address and a token made for you) and run it:

```
claude mcp add --transport http intakt http://localhost:7781/mcp/owner --header "Authorization: Bearer <the printed token>"
```

Then ask the agent to start a review with the values of the printed review command: project `sample`, change `sample-change`, base `main`, repository `/app/var/data/sample-repository`, and the text after `--task=` as the task ([details](docs/user/mcp.md)); how a review runs inside is drawn in [the review flow](docs/architecture/review-flow.md). The [demo walkthrough](docs/guide/demo-walkthrough.md) tells the same path in plain words.

### Timed run

`(owner provides)`: the run needs Docker and a live provider key, which the build sandbox has neither of, so no timing or verdict is recorded yet. The owner times these steps on a clean clone (note `.env.local`, not `.env`: `compose.yaml` reads `.env.local`) and fills in the table:

| Step | Command | Time |
| --- | --- | --- |
| 1. Clone | `git clone https://github.com/shinyredapples/intakt.git && cd intakt` | |
| 2. Configure | `cp .env.dist .env.local`, then set one provider key (or `HF_TOKEN`) and the two secrets | |
| 3. Start | `docker compose up`, until the log prints the review command | |
| 4. First verdict | the printed `docker compose exec intakt php bin/console intakt:review sample-change ...` | |
| Total | | |

Also record the date, the Intakt commit, the host (CPU, Docker version), the first verdict (decision, finding count with severities) and, with two keys set after `docker compose down -v`, that the seeded panel uses two providers, and, with only `HF_TOKEN`, that both reviewers return a verdict on models of two makers.

What works without Docker or a provider key:

```
composer install
bin/check            # the quality gates every change passes (not the journeys), one JSON line per gate
bin/check --journeys # the user journeys, each on a fresh install, run separately
bin/console about    # the app boots on SQLite
```

What the journeys cover and how to run one: [Checking the whole product](docs/guide/checking-the-whole-product.md).

## The first release: the review loop

Built for the [Symfony AI & MCP Hackathon](docs/spec.md) (deadline 2026-11-22):

- Point Intakt at a pull request. A configurable panel of reviewers, each a provider, a model tier and a prompt through Symfony AI's Platform, returns structured JSON verdicts.
- Findings keep their ids across rounds, so reviewers cannot move the goalposts. A judge step tells the builder what to act on.
- Agents start reviews and read findings through an MCP server.
- A small live dashboard shows each round as it streams.
- `docker compose up` with a sample repository and your API keys (or a Hugging Face token) runs it in five minutes.

## Status

**Works today:** the review loop and its foundation. A reviewer panel of any size, at least one reviewer (`config/intakt.yaml`) reviews a branch of a local repository (`intakt:review`, the dashboard button or the MCP tool `start_review`), a judge sorts the findings into the act list, and the dashboard shows each round live; the demo runs it on a sample repository with `docker compose up`. Under it, a Symfony 8.1 app on PHP 8.5.12 or later with SQLite, Symfony AI 1.0.0-BETA1 (Platform, Agent, Store, AI Bundle) and the MCP Bundle installed, the module layout with plugin contracts, the Anthropic, OpenAI and OpenAI-compatible providers with keys from the environment or Symfony secrets (a provider without its key is switched off with a message), and `bin/check`, which runs every quality gate CI runs: coding standards, PHPStan at max (with the service ids and parameter types of the compiled container), Rector, module boundaries, PHPUnit 13, `composer audit`, gitleaks on files and history, commit subjects, long dashes, the `docs/highlights.md` table lint, a row there for every `upstream/` reproduction and the service container lint.

**Planned:** reviewing a pull request on GitHub and posting the result there, rounds started by the loop itself, the catch-up review after a substitute, and everything outside the review loop (tasks, building, merging), in the order of [the plan](docs/plan.md).

## How it is built

- **PHP 8.5 and Symfony 8.1**, with the latest stable tools, and Symfony AI used for every model call, agent and memory, on purpose, to find the beta's bugs early.
- **FrankenPHP worker mode** for the dashboard and the MCP server, with Mercure built in for live updates; Messenger consumers and the Scheduler (the watcher tick every minute, started in the image by its own consumer; usage probes, deadlines and the weekly cleanup are planned) run beside it. The local API stays planned.
- **Steering by labels** (planned) on your forge: `intakt` to take a task, then `priority:`, `class:`, `model:`, `plan:` and `review:`; Intakt mirrors progress in `status:`.
- **No public resource API in v1.** The interfaces are the dashboard, the CLI, an optional forge webhook endpoint (forge changes are polled by default) and the MCP server.

## Documentation

- [Intakt in plain words](docs/guide/README.md): friendly guides with diagrams, from the big picture to troubleshooting.
- [Contributing](CONTRIBUTING.md): reporting bugs, suggesting features, sending pull requests.
- [User guide](docs/user/README.md): for owners running Intakt.
- [Technical pages](docs/architecture/README.md): how it works, the gates, the module layout.
- [Design](docs/design/README.md): what Intakt does and why.
- [Highlights page](https://github.com/shinyredapples/intakt/blob/highlights-page/highlights.md): every Symfony and Symfony AI feature Intakt uses, rebuilt from main.
- [Releases](https://github.com/shinyredapples/intakt/releases): tagged versions and their notes; [how a release is cut](docs/releasing.md).
- [Why the AI can't wreck your project](docs/guide/why-the-ai-cant-wreck-your-project.md): the safety design in plain words.
- [Showcase](docs/showcase/README.md): this repository built by an AI loop, in figures and examples.
- [Spec](docs/spec.md) and [plan](docs/plan.md) of the first release.
- [AGENTS.md](AGENTS.md): the rules for anyone, person or agent, changing the code.

## License

MIT, see [LICENSE](LICENSE) and [NOTICE](NOTICE).
