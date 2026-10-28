# Intakt

<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="docs/assets/intakt-lockup-dark.svg">
    <img src="docs/assets/intakt-lockup.svg" alt="intakt" width="420">
  </picture>
</p>

Give it a spec: AI agents plan, build, review and merge the work on your repository. PHP 8.5, Symfony AI.

Intakt turns a spec into a plan of small tasks, has AI agents build each one, has a panel of other agents from different providers review it, and merges what passes. It spends the AI budget you give it, asks you only when a person is needed, and acts as its own forge identity, never as you.

## The first release: the review loop

Built for the [Symfony AI & MCP Hackathon](docs/spec.md) (deadline 2026-11-22):

- Point Intakt at a pull request. A configurable panel of reviewers, each a provider, a model tier and a prompt through Symfony AI's Platform, returns structured JSON verdicts.
- Findings keep their ids across rounds, so reviewers cannot move the goalposts. A judge step tells the builder what to act on.
- Agents start reviews and read findings through an MCP server.
- A small live dashboard shows each round as it streams.
- `docker compose up` with a sample repository and your API keys runs it in five minutes.

## Status

**Works today:** the foundation. A Symfony 8.1 app on PHP 8.5.12 or later with SQLite, Symfony AI 1.0.0-BETA1 (Platform, Agent, Store, AI Bundle) and the MCP Bundle installed, the module layout with plugin contracts, the Anthropic, OpenAI and OpenAI-compatible providers with keys from the environment or Symfony secrets (a provider without its key is switched off with a message), and `bin/check`, which runs every quality gate CI runs: coding standards, PHPStan at max, Rector, module boundaries, PHPUnit 13, `composer audit`, gitleaks on files and history, commit subjects and long dashes.

**Planned:** everything in the list above, in the order of [the plan](docs/plan.md).

## Quick start

What works now:

```
composer install
bin/check            # every quality gate, one JSON line per gate
bin/console about    # the app boots on SQLite
```

The demo (planned):

```
git clone https://github.com/shinyredapples/intakt.git && cd intakt
cp .env.dist .env.local   # add one or more provider API keys
docker compose up         # Intakt, its database and a sample repository
```

Then open the dashboard, pick the sample pull request and start a review.

## How it is built

- **PHP 8.5 and Symfony 8.1**, with the latest stable tools, and Symfony AI used for every model call, agent and memory, on purpose, to find the beta's bugs early.
- **FrankenPHP worker mode** (planned) for the dashboard, the local API and the MCP server, with Mercure built in for live updates; Messenger consumers and the Scheduler run beside it.
- **Steering by labels** (planned) on your forge: `intakt` to take a task, then `priority:`, `class:`, `model:`, `plan:` and `review:`; Intakt mirrors progress in `status:`.
- **No public resource API in v1.** The interfaces are the dashboard, the CLI, forge webhooks and the MCP server.

## Documentation

- [How Intakt works, in plain words](docs/how-intakt-works.md): the friendly tour, with diagrams.
- [The journey of a task, step by step](docs/the-journey-of-a-task.md): from picking to merging, in plain words.
- [How Intakt uses Symfony, in plain words](docs/symfony-in-intakt.md): which Symfony and Symfony AI parts do what.
- [User guide](docs/user/README.md): for owners running Intakt.
- [Technical pages](docs/architecture/README.md): how it works, the gates, the module layout.
- [Design](docs/design/README.md): what Intakt does and why.
- [Spec](docs/spec.md) and [plan](docs/plan.md) of the first release.
- [AGENTS.md](AGENTS.md): the rules for anyone, person or agent, changing the code.

## License

MIT, see [LICENSE](LICENSE) and [NOTICE](NOTICE).
