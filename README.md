# Intakt

Intakt is a loop runner for AI coding agents. It takes tasks from your forge, has AI agents build them, has a panel of other AI agents review them, and merges what passes, within the budget of the AI accounts you give it.

The first release is the review loop: point Intakt at a pull request and a configurable panel of reviewers, from several AI providers through [Symfony AI](https://github.com/symfony/ai), returns structured verdicts with findings that keep their ids across rounds. A judge step decides which findings the builder must act on. Agents reach the same reviews through an MCP server, and a small live dashboard shows each round as it happens.

Status: foundation only. The skeleton, quality gates and CI are in place; the review loop is planned in [docs/plan.md](docs/plan.md). Everything marked *planned* below does not exist yet.

## From clone to demo in five minutes (planned)

```
git clone <this repository> intakt && cd intakt
cp .env.dist .env.local            # planned: add one or more provider API keys
docker compose up                  # planned: Intakt, its database and a sample repository
```

Then open the dashboard (planned: http://localhost:7780), pick the sample pull request and start a review. The panel, the models and the judge are set in one config file.

## What works today

```
composer install
bin/check            # every quality gate, one JSON line per gate
bin/console about    # the Symfony 8 application boots on SQLite
```

## Documentation

- [User guide](docs/user/README.md): for owners running Intakt.
- [Technical pages](docs/architecture/README.md): how it works.
- [Design](docs/design/README.md): what Intakt does and why.
- [Spec](docs/spec.md) and [plan](docs/plan.md) of the first release.
- [AGENTS.md](AGENTS.md): the rules for anyone, human or agent, changing the code.

## License

MIT, see [LICENSE](LICENSE) and [NOTICE](NOTICE).
