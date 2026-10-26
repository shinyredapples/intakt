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
- A likely library bug becomes a minimal reproduction and draft report in `upstream/<name>/`; never posted without the owner.

## Docs, pull requests, commits

- User-visible changes update `docs/user/` and `docs/architecture/` in the same change; docs state only what the code does.
- One task per pull request, mergeable alone, with an **Evidence** section from a running install; `Waits for #n` and `Source: <branch>@<sha>` lines.
- Conventional Commits, at most 100 characters; US English, no em-dashes. Run `bin/check` before every commit.

## Secrets and protected paths

- Nothing secret in the repository; gitleaks scans files and history, and inline allow directives are refused.
- `config/gates/`, `bin/check`, `.github/`, `AGENTS.md`, `composer.json`, `composer.lock` and the PHPUnit configs need the owner's approval (`.github/CODEOWNERS`; a code-owner ruleset is turned on when the repository goes public, since a private free-plan repository cannot enforce it). Never weaken a gate, inline or otherwise.
- The `comments` gate checks why-comments with a reason-word heuristic; reviewers are the backstop.
