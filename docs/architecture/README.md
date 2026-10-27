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
| `phpstan` | PHPStan at max with strict and deprecation rules, no baseline, cognitive complexity, at most three parameters, no nested `try`, time only through the clock (one adapter class exempt), no opt-out of mock expectations |
| `rector` | Rector dry run: dead code, code quality, class constants over class-name strings |
| `architecture` | PHPArkitect: module layout, other modules only through `Contract`, inner layers independent |
| `comments` | line comments give a reason on one line (a reason-word heuristic; reviewers are the backstop); docblocks hold types only; no TODOs, commented-out code or suppression directives (`@phpstan-ignore*`, `@psalm-suppress`, `@noRector` and the like), so no gate can be weakened inline |
| `audit` | `composer audit` on the lock file |
| `tests` | PHPUnit on SQLite (`phpunit.dist.xml`) and the gate tests (`phpunit.gates.xml`), failing on warnings, notices, PHPUnit notices, deprecations, risky and skipped tests; every test class declares its coverage |
| `secrets` | gitleaks on the working tree, refusing inline allow directives |
| `secrets-history` | gitleaks on every commit of the change |
| `commits` | Conventional Commit subjects, at most 100 characters |
| `dashes` | no em-dashes or other long dashes in files or commit messages |

Options: `--fast` (changed PHP files only), `--only=<gate,...>`, `--skip=<gate,...>`, `--base=<ref>` (the base of the change, default `origin/main`).

## Instance and probes

Two entry points let reviewers and the boot gate exercise a running Intakt without network access, after `composer install` (plan task T31). `bin/instance` exists; `bin/probe` is planned.

- `bin/instance` boots the app from a clean checkout with the plain Symfony runtime (PHP's built-in web server on a free port of `127.0.0.1`, SQLite in a scratch directory), prints one JSON status line, `ready` or `failed` with the reason, and stops cleanly. FrankenPHP is a binary, not a Composer package: worker mode comes with the container image (plan task T22), where `bin/instance` boots in worker mode.
- `bin/probe <file>` (planned) boots the same instance and runs a reviewer's small PHPUnit test or PHP script against it, with no network, and prints its output and exit code.

How `bin/instance` works:

1. It takes no argument and needs no Composer autoloader itself, so a broken install still reports `failed`.
2. It creates a scratch directory under `$TMPDIR` (under `var/` when unset) and points the cache, log and share directories and `DATABASE_URL` into it. `APP_ENV` is taken from the environment (default `test`); `APP_DEBUG` is always `0`, because a debug kernel writes `config/reference.php` into the checkout.
3. It starts `php -d variables_order=EGPCS -S 127.0.0.1:0`, so the runtime sees that environment instead of falling back to `.env`, and the server binds a free port itself (no other process can take it between a probe and the bind). A `sh -c 'exec "$@" 3>&-'` wrapper closes the caller's descriptor 3, and the descriptor spec holds only 0 to 2 (stdout and stderr go to a log in the scratch directory), so the server never holds the status descriptor.
4. Once the server log reports the port it listens on, it requests `/` on that port until the server answers, for at most 30 seconds. A status code below 500 is `ready` (a 404 shows the kernel booted). A status code of 500 or more, a status line it cannot parse, an early exit or no answer is `failed`. The reason is the first log line that mentions an error or exception, `no error in the server log` when there is none, or the unparsable status line.
5. It stops the server (SIGTERM, then SIGKILL after 5 seconds), removes the scratch directory and prints the server log to stdout. Only then does it open descriptor 3, once, and write the status line, so nothing it started can inherit a duplicate of it.

Contract with the build loop: the status line is `{"status":"ready"}` or `{"status":"failed","reason":"..."}` on descriptor 3 only; stdout and stderr are the log. The exit code is 0 exactly when `ready` was reported. When descriptor 3 is not open, no status is written and the exit code is 1. The status is an `InstanceStatus` marked `#[NoDiscard]`, so the script cannot drop it. `tests/Application/InstanceTest.php` covers a clean boot, a broken kernel configuration, an unparsable status line, the server's descriptors (read from `/proc` while it runs, with a caller holding descriptors 3 to 14), leftovers in `$TMPDIR` and in the project tree, and the missing descriptor.

## Conventions

- **Logging** is structured (context arrays, no interpolated secrets) and passes through a redaction processor that strips tokens and API keys; the processor has its own tests.
- **Doctrine mapping** uses attributes with the `ORM` alias, and each module's entities and mapping live in that module.
- **Dependencies:** official Symfony and Doctrine packages first. Any other package needs a release within the last 12 months and a clean `composer audit`, and the owner approves it, since `composer.json` is a protected path.
- **Message handlers** use Intakt's `AsCommandHandler` and `AsQueryHandler` (in the platform module's `Contract`, with a `Bus` enum), which extend Symfony's `AsMessageHandler` and fix the bus; a PHPArkitect rule or guard test refuses `AsMessageHandler` anywhere else. They arrive with the first message handler (plan task T9).

## Persistence

SQLite by default (`DATABASE_URL` in `.env`). MySQL, MariaDB and PostgreSQL are supported targets; their CI jobs start with the first entity (see [the plan](../plan.md)).
