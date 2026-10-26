# Working on Intakt

Intakt is a loop runner for AI coding agents, built on PHP 8.5, Symfony 8 and Symfony AI. The design in `docs/design/` is the authority (start at `docs/design/README.md`); the spec and plan for the current release are `docs/spec.md` and `docs/plan.md`. This file is the short version of the rules.

## How code reads

- **Storytelling.** A use case's entry method reads as a short story of named steps in domain words, top-down. The public method comes first, then its helpers in the order they are called. A newcomer can follow a flow from its door (command, controller, handler, MCP tool) to the result without jumping through layers of indirection.
- **SOLID, pragmatically.** One reason to change per class. Depend on a module's `Contract`, not its insides. An interface exists only at a module boundary or when a second implementation exists today, never for a single implementation inside a module. No factories, strategies, abstract base classes or generic frameworks until a second real case needs them.
- **DRY without speculation.** Duplicated knowledge (a rule, a format, a decision) has one home. Similar-looking code is extracted on the third occurrence, not the second, and never into a shared "utils".
- **No overengineering.** A module gets its `Domain/`, `UseCase/` and `Infrastructure/` folders only when they hold code. No config option, column, parameter or extension point without a caller today. Plain PHP and Symfony features before custom abstractions.
- **Comments say why, never what.** Names and small methods carry the meaning. A comment may only give a reason (a constraint, a trade-off, a link to an upstream bug) on one line; no commented-out code, no TODOs. Docblocks hold types only (`@param`, `@return`, `@var`, `@template`, `@throws`). The `comments` gate enforces this: a line comment must give a reason (words such as *because*, *so*, *otherwise*, *must*, or a link), and a docblock with prose fails.

## Modules and contracts

- Code lives in modules: `src/Core/<Module>/` for core areas, `src/Plugin/<Kind>/` for plugin kinds (Forge, Vendor, Notifier, WorkSource, Gate). Every module is listed in `config/modules.php`.
- Inside a module: `Contract/` is the only part other modules may use; `Domain/` holds entities and value objects; `UseCase/<Name>/` holds one use case and every door into it (command, controller, message handler, MCP tool); `Infrastructure/` holds adapters (Doctrine, HTTP, the Docker API).
- `Domain` and `Contract` never depend on `UseCase` or `Infrastructure` of their own module. The `architecture` gate (PHPArkitect) enforces all of this.
- A plugin implementation is a module of its own (for example `src/Plugin/GitHubForge/`) that uses only the kind's `Contract`.
- Add a contract method only when a caller needs it. No speculative interfaces, columns, config keys or options.

## Code

- PHP 8.5 (8.5.12 or later), `declare(strict_types=1)`, final classes, `readonly` where possible, no public setters.
- Use PHP 8.5 features where they make the code clearer, never for show:
  - the pipe operator `|>` for step-by-step transformations;
  - `clone()` with property overrides for readonly value objects;
  - `#[\NoDiscard]` on results that must not be ignored, such as gate outcomes and token mints;
  - `array_first()` and `array_last()`;
  - the URI extension (`Uri\Rfc3986\Uri`) instead of `parse_url` for forge and webhook URLs;
  - closures in constant expressions and attributes.
- Small methods that read as a sequence of named steps; early returns; no boolean flag parameters; at most three parameters per method or closure (enforced).
- The HTTP side runs in FrankenPHP worker mode: one kernel serves many requests. Services keep no per-request state; anything that must be cleared between requests implements `ResetInterface`.
- Domain names in code; commands imperative, events past tense.

## Tests

- Every change brings its tests; test names are sentences; one behavior per test.
- Tests run on SQLite by default. Tests that touch persistence go in the `persistence` group, so CI can run them on MySQL, MariaDB and PostgreSQL too.
- Races and ordering are proven with a forced-order test, never with repeated runs.
- Tests never read the shipped config or real credentials; fixtures start from built-in defaults. Model calls in tests go through Symfony AI's in-memory platform or recorded responses, never a live provider.

## Symfony AI

- Use Symfony AI fully: the Platform for every model call (structured output, streaming, token usage), the Agent for every role, the Store for memory, the AI Bundle for configuration, the MCP Bundle and the PHP MCP SDK for MCP server and client. Keep Intakt's own small vendor contract on top so a provider can be swapped.
- Versions are pinned exactly in `composer.json`.
- A likely bug in Symfony AI or the MCP SDK gets a minimal reproduction (a failing test or a short script) and a draft report with the exact versions in `upstream/<short-name>/`. Never post it upstream; the owner decides.

## Documentation

- Every user-visible change updates `docs/user/` (plain guide: what it does, how to set it up, what to do when it goes wrong) and `docs/architecture/` (how it works, the data it keeps, its limits, a Mermaid diagram where the flow is not obvious) in the same change.
- Docs state only what the code does. Commands are shown with their real output; config examples load.
- US English, short sentences, no em-dashes (the `dashes` gate fails on them, in files and commit messages).

## Pull requests and Evidence

- One task, one branch, one pull request that is mergeable on its own with its tests.
- The description has an **Evidence** section: what was run on a running install and what it showed (command and output, a screenshot path, a log excerpt). "Tests pass" is not evidence of a feature.
- Tasks use `Waits for #n` lines for dependencies and `Source: <branch>@<sha>` for split parts.

## Commits

- Conventional Commits: `type(scope): subject`, at most 100 characters, types `feat fix docs refactor test perf build ci chore revert style`. The `commits` gate checks every commit of the change.
- Run `bin/check` before every commit; it runs what CI runs and prints one JSON line per gate. `bin/check --only=<gate>` and `--fast` (changed files only) help while working.

## Secrets

- Nothing secret in the repository: no keys, tokens, passwords, private URLs or real `.env.local` values. API keys come from the environment or Symfony secrets outside the repository.
- The `secrets` and `secrets-history` gates (gitleaks) scan the files and every commit of the change. Inline allow directives and `.gitleaksignore` are refused.

## Protected paths

`config/gates/`, `bin/check`, `.github/`, `AGENTS.md`, `composer.json`, `composer.lock`, `phpunit.dist.xml` and `phpunit.gates.xml` define the rules. Changes to them need the owner's approval; never weaken a gate to make a change pass. `.github/CODEOWNERS` names the owner as their reviewer; a branch ruleset requiring code-owner review is turned on when the repository goes public (a private repository on the free plan cannot enforce it).
