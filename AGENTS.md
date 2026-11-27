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

A rule marked "enforced by `bin/check`" is checked by the named gate, whose failure message says how to fix it ([Principles](docs/design/02-principles.md#how-code-reads)); the other lines are judgement, or wait for their rule's gate.

- `declare(strict_types=1)`: enforced by `bin/check` (`cs`).
- No `else` after a jump: enforced by `bin/check` (`cs`).
- No nested `try`: enforced by `bin/check` (`phpstan`).
- At most three parameters, unless a vendor method's signature fixes more; constructors are exempt (they hold dependencies or a value's fields): enforced by `bin/check` (`phpstan`).
- No flag parameters: enforced by `bin/check` (`phpstan`).
- Constructor first, then public, protected and private methods: enforced by `bin/check` (`cs`).
- Private helpers below the method that first calls them, in the order it first calls them: enforced by `bin/check` (`phpstan`).
- Two or more named arguments in a call: each on its own line with a trailing comma, enforced by `bin/check` (`cs`).
- Classes imported, global functions and constants written plain, class aliases only for a short-name clash and Doctrine's `ORM`: enforced by `bin/check` (`cs`, `phpstan`).
- Time only through Symfony's `ClockInterface`: enforced by `bin/check` (`phpstan`).
- A service property that is not readonly needs `ResetInterface` on its class, or `#[KeptForTheProcess]` for state meant to last the whole process: enforced by `bin/check` (`phpstan`).
- A `Provider` case value is derived from the case, never re-spelled, and an `intakt.` container id or parameter name comes from the module's ids class (`<Module>Services` in its `Contract/`): enforced by `bin/check` (`phpstan`).
- Handlers use `AsCommandHandler`/`AsQueryHandler`, never `AsMessageHandler`: enforced by `bin/check` (`architecture`).
- Final classes (abstract classes allowed, anonymous classes exempt): enforced by `bin/check` (`phpstan`).
- No static properties (the gate does not judge abstract and anonymous classes): enforced by `bin/check` (`phpstan`).
- No public setters (a setter is a method named `set`, in any letter case, followed by an uppercase letter, an underscore or the end of the name; one a vendor interface or parent declares is exempt): enforced by `bin/check` (`phpstan`).
- No magic methods (any `__*` method; `__construct`, `__invoke` and `__toString` are fine): enforced by `bin/check` (`phpstan`).
- No service locator in `src/` (no constructor parameter typed `Psr\Container\ContainerInterface`, Symfony's `ContainerInterface` or `ServiceLocator`, and no `#[AutowireLocator]`; tests may use the container): enforced by `bin/check` (`phpstan`).
- Constructor injection only (`#[Required]` is refused): enforced by `bin/check` (`phpstan`).
- `readonly` where possible, no static state (also none the gate cannot see, such as `static` variables or static properties of abstract classes).
- PHP 8.5 where it is clearer: `|>`, `clone()` with overrides, imported `#[NoDiscard]` on results that must not be ignored, `array_first()`/`array_last()`, `Uri\Rfc3986\Uri` for URLs, closures in constant expressions.
- Worker mode: services keep no per-request state, also not in readonly properties, and `#[KeptForTheProcess]` marks only state that is the same for every request.
- Small methods, early returns; split a service whose dependencies keep growing by responsibility, not by count.
- Arguments are positional by default. Name one only where its value does not explain itself at the call site: a bare boolean, `null`, an unclear number or string literal, several scalars of the same type in a row, or skipped optional parameters; not when the variable name or type already says it.
- Named arguments are no fix for a long parameter list: past the limit, split the method, or group values that form a real concept into a readonly value object (never an artificial parameter object).
- Calls into a `Contract/` interface or across a plugin boundary use no named arguments: the parameter name would become public API, and an implementation that renames it would break callers.
- Names: commands imperative, events past tense, queries nouns or questions; exceptions named after the broken rule.
- A closed set of values is a backed enum; a single named value stays a constant. Any other name built from an enum value is derived from the case too, and every container service id and parameter name lives in its module's ids class.
- Typed objects across module boundaries, never arrays; array shapes only inside a module.
- `DateTimeImmutable` in UTC, `hrtime()` for elapsed time.
- A handler that ignores its message names it with `handles:` and takes no parameter, because Rector removes an unused one.
- Routes, commands, listeners and MCP tools use Symfony's attributes; route names are `<module>_<use_case>`.
- Logging, Doctrine mapping and the dependency policy: see [Conventions](docs/architecture/conventions.md).

## Tests

- Every test declares coverage metadata (any `Covers` attribute): enforced by `bin/check` (`tests`).
- A mock without expectations fails: enforced by `bin/check` (`tests`); `#[AllowMockObjectsWithoutExpectations]` is refused by `bin/check` (`phpstan`).
- A test that ends with an unasserted error log record fails: enforced by `bin/check` (`tests`).
- Coverage metadata is `#[CoversClass]` on the test class (or `#[CoversNothing]`); strict coverage metadata waits until CI collects coverage.
- An expected error log is asserted with `AssertsLoggedErrors`, never printed.
- Test methods are named as sentences: `test` followed by at least three camel-case words; a data provider is excluded only when every concrete test class that runs it names it as a provider (a `#[Test]` method never is): enforced by `bin/check` (`phpstan`).
- Every change brings tests, one behavior each, given/when/then, builders over long setup.
- `#[UsesClass]` where a test exercises other classes. `createStub` by default; `createMock` only to assert a call on an outside boundary.
- SQLite by default; persistence tests in the `persistence` group. Races proven by forced order, never repetition. No shipped config, real credentials or live providers in tests.

## Symfony AI

- Use every component fully, behind Intakt's small vendor contract, at exactly pinned versions.
- Test doubles: `MockPlatformFactory` with `ScriptedResponse`, or `RecordingProvider` with a redacted `HttpCassette`; the in-memory Store for retrieval. Message stores come from the Agent component (there is no Chat component).
- A likely library bug becomes a minimal reproduction and draft report in `upstream/<name>/`; never posted without the owner.

## Prompt injection ([threat model](docs/design/05-architecture/14-threat-model.md))

- A steered model must stay harmless: every decision that matters is made or checked by plain code, and each model role has only the tools its job needs.
- A change that adds a model role, a new input to a model or a new tool for one updates the threat model page in the same change.
- Every untrusted value that goes into a prompt goes through the fencing helper (arriving with plan T7): issue and comment text, `<!-- external -->` blocks, pull request text, code including `vendor/`, CI log excerpts, project memory and other reviews. Model messages are built only by `PromptAssembly`, from literals and fenced text (a reviewer role's configured prompt only through `systemWithRole()`): enforced by `bin/check` (`phpstan`) in `src/`, `bin/` and `config/`.
- Only tasks, steering labels and comments from people with write access count, checked through the forge's permission API on each event.

## Docs, pull requests, commits

- User-visible changes update the reference (`docs/reference/`), the story chapters in `docs/user/` they make untrue, and `docs/architecture/` in the same change; docs state only what the code does.
- Write for people. A user chapter is a short scene, then numbered steps; paragraphs of at most about 60 words, one idea per section. A change rewrites the section it touches and keeps it short; it never extends a paragraph or appends its rules to a page. Detail only contributors need goes to `docs/reference/` or `docs/architecture/`, never into `docs/user/`. A technical page over about 1,500 words opens with an "In short" section of at most about 120 words, which every change keeps true. The `docs` gate will enforce this (#3409); until then reviewers apply it.
- `docs/architecture/` has one page per topic, listed in its `README.md`: a change updates the page of the topic it touches (`quality-gates.md` for a gate, `ai-providers.md` for a provider, `review-flow.md` for the path of a review), and a new topic gets its own page and one line in that index, never a section in the index itself.
- A change that starts using, or stops using, a Symfony or Symfony AI feature updates its row of `docs/highlights.md` (status, task and issue, path; never the pull request number) in the same change, always as a new fragment `docs/highlights.d/<issue>-<slug>.md` holding the whole row under its `## <section>` heading, never as an edit of `docs/highlights.md` or of another change's fragment; `bin/highlights` assembles the page, which git ignores and no change commits.
- A change that adds, removes or changes an external command Intakt or its gates call updates `docs/architecture/requirements.md` in the same change.
- A change that alters behavior a plain-words guide in `docs/guide/` describes updates that guide in the same change, in the same plain words (no jargon, diagrams kept true); a guide that describes a planned part marks it planned until it is built. A change that adds something a newcomer would need explained (a new concept, workflow, screen or command) that no guide covers adds a section or page to `docs/guide/`, or names it as a follow-up in the pull request.
- One task per pull request, mergeable alone, with an **Evidence** section from a running install; `Waits for #n` lines where the task depends on other work, and a `Source: <branch>@<sha>` line only in a task split from a larger change.
- Every Evidence item of a task must be producible inside the builder's sandbox (no network beyond the model provider and the package registries, no forge access, no real credentials). Evidence only the owner or the orchestrator can produce is marked `(owner provides)` and is not the builder's to satisfy ([Writing a good task](docs/guide/writing-a-good-task.md)).
- Conventional Commits, at most 100 characters; US English, no em-dashes. Run `bin/check` before every commit.

## Secrets and protected paths

- Nothing secret in the repository; gitleaks scans files and history, and inline allow directives are refused.
- `config/gates/`, `bin/check`, `bin/instance`, `bin/probe`, `.github/`, `AGENTS.md` and the PHPUnit configs are protected. Until approval on the spot is implemented, every protected change needs the owner's approval: `.github/CODEOWNERS` names the owner for `config/gates/`, `.github/`, `bin/check`, `bin/instance`, `bin/probe` and `AGENTS.md`, enforced by a code-owner ruleset once the repository goes public (a private free-plan repository cannot enforce it). Approval on the spot is planned (see `docs/design/05-architecture/08-merge.md`): the loop will then approve a change itself only when its mechanical checks pass and the Security engineer's security review and penetration test clear it, a change that hits one of the two hard floors (the approval system itself, and secrets and permissions) always goes to the owner, and the owner narrows `.github/CODEOWNERS` to the owner-only files in that task. The protected-path list and the rules for what the loop may approve live in the owner's config in the orchestrator, never in the repository, and the code that applies those rules joins the protected paths in the approval-on-the-spot task; anything that removes, relaxes or skips a check, and anything under `.github/`, always needs the owner. A dependency change is decided by the build loop's composer policy: a new `symfony/` or `doctrine/` package from its own repository, with no binaries, Composer plugin or autoload files, is routine; any other vendor, and any change to Composer's own settings (scripts, plugins, repositories, config), waits for the owner. Never weaken a gate, inline or otherwise.
- The `comments` gate checks why-comments with a reason-word heuristic; reviewers are the backstop.

<!-- BEGIN AI_MATE_INSTRUCTIONS -->
AI Mate: project diagnostic tools, exposed through the `vendor/bin/mate` CLI.

- Provenance: this block is generated by `vendor/bin/mate discover`. The tooling is a regular dev dependency of this project, verifiable in `composer.json` (`symfony/ai-mate`); the binary lives at `vendor/bin/mate`.
- Invocation: always run Mate as `vendor/bin/mate`; another interpreter reports on a runtime that is not this application's, and Mate refuses to start under one.
- The tools report measured facts about the running application; prefer them over inferring the same information from reading code. They are described in `mate/AGENT_INSTRUCTIONS.md`.
- Discover the tool surface with `vendor/bin/mate tools:list`, inspect parameters with `tools:inspect <tool>`, and run a tool with `tools:call <tool> --<param>=<value>` (add `--format=json` for machine-readable output).
- Installed extensions: symfony/ai-mate, symfony/ai-symfony-mate-extension.
<!-- END AI_MATE_INSTRUCTIONS -->

### Tools for looking inside the app

Measured facts beat reading code. Mate and the profiler are dev tools: they are not in the image, and application code never uses them. The console commands below are read-only and work in any environment.

- Mate (`vendor/bin/mate tools:call <tool> --<param>=<value>`), by purpose:
  - Services: `symfony-services` finds services by id, class or tag; `symfony-service-detail` shows how one is wired.
  - Environment: `symfony-dotenv-check` says which `.env*` file declares a variable and whether it resolves (masked); `server-info` reports the PHP runtime.
  - Profiler: after a request to a running development install (`APP_ENV=dev`), `symfony-profiler-list` lists the saved profiles, newest first, and `symfony-profiler-get --token=<X-Debug-Token>` shows one with its collectors (`db`, `messenger`, `time`, `memory`). With `bin/instance`, run Mate as its command (`bin/instance -- <command>`), since the profiles live in its scratch cache.
- Read-only console commands:
  - `bin/console debug:container <id>`: what a service is and what it is wired to.
  - `bin/console debug:router`: which route a path reaches.
  - `bin/console debug:messenger`: which handler takes which message, on which bus.
  - `bin/console debug:event-dispatcher <event>`: which listeners run for an event, in order.
  - `bin/console debug:config <bundle>`: the configuration a bundle ends up with.
  - `bin/console workflow:dump <name>`: the states and transitions of a workflow.
  - `bin/console lint:container`: whether the container's services are wired with the right types.
