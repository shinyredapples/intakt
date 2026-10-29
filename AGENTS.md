# Working on Intakt

Intakt is a loop runner for AI coding agents on PHP 8.5 (8.5.12 or later), Symfony 8 and Symfony AI. The design in `docs/design/` is the authority (start at `docs/design/README.md`); the current release is `docs/spec.md` and `docs/plan.md`. This file is the short version.

## How code reads ([principles](docs/design/02-principles.md#how-code-reads))

- **Storytelling:** an entry method reads as named steps in domain words; public method first, helpers below in call order.
- **SOLID, pragmatically:** one reason to change per class; interfaces only at module boundaries or for a second real implementation.
- **DRY without speculation:** knowledge has one home; extract similar code on the third occurrence, never into "utils".
- **No overengineering:** no folder, option, column, parameter or extension point without a caller today.
- **Comments say why, never what:** one line with a reason or a link; docblocks hold types only.

## Modules

- `src/Core/<Module>/` and `src/Plugin/<Kind>/` (Forge, Vendor, Notifier, WorkSource, Gate), listed in `config/modules.php`.
- Other modules use only a module's `Contract/`; `Domain/` and `Contract/` never depend on `UseCase/` or `Infrastructure/` (the `architecture` gate).
- `UseCase/<Name>/` holds one use case and its doors (command, controller, handler, MCP tool). A plugin implementation is its own module.

## Code

- `declare(strict_types=1)`, final classes, `readonly` where possible, no public setters, no static state, no service locator, no magic methods, constructor injection only.
- PHP 8.5 where it is clearer: `|>`, `clone()` with overrides, imported `#[NoDiscard]` on results that must not be ignored, `array_first()`/`array_last()`, `Uri\Rfc3986\Uri` for URLs, closures in constant expressions.
- Small methods, early returns, no flag parameters, at most three parameters, no `else` after a jump, no nested `try` (enforced).
- Imports only, no fully qualified names; the Doctrine `ORM` alias is the one alias.
- Names: commands imperative, events past tense, queries nouns or questions; exceptions named after the broken rule.
- A closed set of values is a backed enum; a single named value stays a constant.
- Typed objects across module boundaries, never arrays; array shapes only inside a module.
- Time only through Symfony's `ClockInterface`, `DateTimeImmutable` in UTC; system-time functions and non-literal moments are refused (enforced). `hrtime()` for elapsed time.
- Worker mode: services keep no per-request state; what must reset implements `ResetInterface`.
- Handlers use Intakt's `AsCommandHandler`/`AsQueryHandler`, never `AsMessageHandler` (arriving with the first handler, plan T9). A handler that ignores its message names it with `handles:` and takes no parameter, because Rector removes an unused one.
- Routes, commands, listeners and MCP tools use Symfony's attributes; route names are `<module>_<use_case>`.
- Logging, Doctrine mapping and the dependency policy: see [Conventions](docs/architecture/README.md#conventions).

## Tests

- Every change brings tests named as sentences (`test...`), one behavior each, given/when/then, builders over long setup.
- Every test class declares `#[CoversClass]` (or `#[CoversNothing]`), `#[UsesClass]` where it exercises other classes; strict coverage metadata waits until CI collects coverage.
- `createStub` by default; `createMock` only to assert a call on an outside boundary. A mock without expectations fails the `tests` gate; `#[AllowMockObjectsWithoutExpectations]` is refused.
- SQLite by default; persistence tests in the `persistence` group. Races proven by forced order, never repetition. No shipped config, real credentials or live providers in tests.

## Symfony AI

- Use every component fully, behind Intakt's small vendor contract, at exactly pinned versions.
- Test doubles: `MockPlatformFactory` with `ScriptedResponse`, or `RecordingProvider` with a redacted `HttpCassette`; the in-memory Store for retrieval. Message stores come from the Agent component (there is no Chat component).
- A likely library bug becomes a minimal reproduction and draft report in `upstream/<name>/`; never posted without the owner.

## Docs, pull requests, commits

- User-visible changes update `docs/user/` and `docs/architecture/` in the same change; docs state only what the code does.
- A change that starts using, or stops using, a Symfony or Symfony AI feature updates its row in `docs/highlights.md` (status, task and issue, path; never the pull request number) in the same change.
- One task per pull request, mergeable alone, with an **Evidence** section from a running install; `Waits for #n` and `Source: <branch>@<sha>` lines.
- Every Evidence item of a task must be producible inside the builder's sandbox (no network beyond the model provider and the package registries, no forge access, no real credentials). Evidence only the owner or the orchestrator can produce is marked `(owner provides)` and is not the builder's to satisfy ([Writing a good task](docs/guide/writing-a-good-task.md)).
- Conventional Commits, at most 100 characters; US English, no em-dashes. Run `bin/check` before every commit.

## Secrets and protected paths

- Nothing secret in the repository; gitleaks scans files and history, and inline allow directives are refused.
- `config/gates/`, `bin/check`, `bin/instance`, `bin/probe`, `.github/`, `AGENTS.md` and the PHPUnit configs are protected. Until approval by class is implemented, every protected change needs the owner's approval: `.github/CODEOWNERS` names the owner for `config/gates/`, `.github/`, `bin/check`, `bin/instance`, `bin/probe` and `AGENTS.md`, enforced by a code-owner ruleset once the repository goes public (a private free-plan repository cannot enforce it). Approval by class is planned (see `docs/design/05-architecture/08-merge.md`): the loop will then approve a change itself only when every reviewer classifies it as tightening or keeping the rules and existing gate code provably only grows, and the owner narrows `.github/CODEOWNERS` to the owner-only files in that task. The protected-path list and the rules for what the loop may approve live in the owner's config in the orchestrator, never in the repository, and the code that applies those rules joins the protected paths in the approval-by-class task; anything that removes, relaxes or skips a check, and anything under `.github/`, always needs the owner. A dependency change is decided by the build loop's composer policy: a new `symfony/` or `doctrine/` package from its own repository, with no binaries, Composer plugin or autoload files, is routine; any other vendor, and any change to Composer's own settings (scripts, plugins, repositories, config), waits for the owner. Never weaken a gate, inline or otherwise.
- The `comments` gate checks why-comments with a reason-word heuristic; reviewers are the backstop.

<!-- BEGIN AI_MATE_INSTRUCTIONS -->
AI Mate: project diagnostic tools, exposed through the `vendor/bin/mate` CLI.

- Provenance: this block is generated by `vendor/bin/mate discover`. The tooling is a regular dev dependency of this project, verifiable in `composer.json` (`symfony/ai-mate`); the binary lives at `vendor/bin/mate`.
- Invocation: always run Mate as `vendor/bin/mate`; another interpreter reports on a runtime that is not this application's, and Mate refuses to start under one.
- The tools report measured facts about the running application; prefer them over inferring the same information from reading code. They are described in `mate/AGENT_INSTRUCTIONS.md`.
- Discover the tool surface with `vendor/bin/mate tools:list`, inspect parameters with `tools:inspect <tool>`, and run a tool with `tools:call <tool> --<param>=<value>` (add `--format=json` for machine-readable output).
- Installed extensions: symfony/ai-mate, symfony/ai-symfony-mate-extension.
<!-- END AI_MATE_INSTRUCTIONS -->

Use Mate before guessing how the container wires a service or where an env variable is set: `tools:call symfony-services`, `symfony-service-detail`, `symfony-dotenv-check`. The profiler tools find nothing, since the app does not enable the profiler. It is a dev tool: nothing of it is in the image, and application code never uses it.
