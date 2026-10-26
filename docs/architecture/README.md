# Intakt technical pages

How Intakt works, for developers and agents. Each user-visible feature has a page here (how it works, the data it keeps, its limits, a Mermaid diagram where the flow is not obvious), written in the same change as the code.

## Module layout

```
src/
  Kernel.php
  Core/<Module>/        core areas (review, ledger, ...), added by the tasks that need them
  Plugin/<Kind>/        plugin kinds: Forge, Vendor, Notifier, WorkSource, Gate
    Contract/           the module's only public surface
    Domain/             entities and value objects
    UseCase/<Name>/     one use case and its doors (command, controller, handler, MCP tool)
    Infrastructure/     adapters
config/modules.php      the module list; a test keeps it equal to the directories
```

The plugin kinds have empty contracts for now. The first implementation of each kind adds the methods it needs (see [Left to the builder](../design/08-left-to-the-builder.md)).

## Quality gates

`bin/check` runs every gate and prints one JSON line per gate, then a result line. CI runs the same command.

| Gate | What it checks |
|---|---|
| `cs` | PHP-CS-Fixer, PER-CS and Symfony rules, strict types, imported names |
| `phpstan` | PHPStan at max with strict and deprecation rules, no baseline, cognitive complexity, at most three parameters, no nested `try`, time only through the clock |
| `rector` | Rector dry run: dead code, code quality, class constants over class-name strings |
| `architecture` | PHPArkitect: module layout, other modules only through `Contract`, inner layers independent |
| `comments` | line comments give a reason (a why word or a link) on one line; docblocks hold types only; no TODOs, commented-out code or suppression directives (`@phpstan-ignore*`, `@psalm-suppress`, `@noRector` and the like), so no gate can be weakened inline |
| `audit` | `composer audit` on the lock file |
| `tests` | PHPUnit on SQLite (`phpunit.dist.xml`) and the gate tests (`phpunit.gates.xml`), failing on warnings, notices, PHPUnit notices, deprecations, risky and skipped tests; every test class declares its coverage |
| `secrets` | gitleaks on the working tree, refusing inline allow directives |
| `secrets-history` | gitleaks on every commit of the change |
| `commits` | Conventional Commit subjects, at most 100 characters |
| `dashes` | no em-dashes or other long dashes in files or commit messages |

Options: `--fast` (changed PHP files only), `--only=<gate,...>`, `--skip=<gate,...>`, `--base=<ref>` (the base of the change, default `origin/main`).

## Instance and probes (planned)

Two entry points let reviewers and the boot gate exercise a running Intakt without network access, after `composer install` (plan task T31):

- `bin/instance` boots the app from a clean checkout with the plain Symfony runtime (PHP's built-in web server, SQLite in a scratch directory), prints one JSON status line, `ready` or `failed` with the reason, and stops cleanly. FrankenPHP is a binary, not a Composer package: worker mode comes with the container image (plan task T22), where `bin/instance` boots in worker mode.
- `bin/probe <file>` boots the same instance and runs a reviewer's small PHPUnit test or PHP script against it, with no network, and prints its output and exit code.

## Persistence

SQLite by default (`DATABASE_URL` in `.env`). MySQL, MariaDB and PostgreSQL are supported targets; their CI jobs start with the first entity (see [the plan](../plan.md)).
