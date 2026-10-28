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

Two entry points let reviewers and the boot gate exercise a running Intakt without network access, after `composer install` (plan task T31). Both exist.

- `bin/instance` boots the app from a clean checkout with the plain Symfony runtime (PHP's built-in web server on a free port of `127.0.0.1`, SQLite in a scratch directory), prints one JSON status line, `ready` or `failed` with the reason, and stops cleanly. FrankenPHP is a binary, not a Composer package: worker mode comes with the container image (plan task T22), where `bin/instance` boots in worker mode.
- `bin/probe <file>` boots the same instance and runs a reviewer's small PHPUnit test or PHP script against it, with network access cut, and prints its output and exit code.

How `bin/instance` works:

1. It needs no Composer autoloader itself, so a broken install still reports `failed`. It takes no argument, except `-- <command...>` (command mode, how `bin/probe` reuses it): once the instance is `ready` it runs that command with stdin from `/dev/null`, stdout and stderr shared, descriptor 3 closed, and `INTAKT_INSTANCE_URL` and the scratch environment set. A command still running after 60 seconds (`INTAKT_INSTANCE_COMMAND_SECONDS` when set to a positive number, which the tests use) is killed with SIGKILL and its status is 124. Only the command itself is killed or waited for: processes it leaves behind are the caller's to contain, which `bin/probe` does with a PID namespace. When the boot fails, the command does not run.
2. It creates a scratch directory under `$TMPDIR` (under `var/` when unset) and points the cache, log and share directories and `DATABASE_URL` into it. `APP_ENV` is taken from the environment (default `test`); `APP_DEBUG` is always `0`, because a debug kernel writes `config/reference.php` into the checkout.
3. It starts `php -d variables_order=EGPCS -S 127.0.0.1:0`, so the runtime sees that environment instead of falling back to `.env`, and the server binds a free port itself (no other process can take it between a probe and the bind). A `sh -c 'exec "$@" 3>&-'` wrapper closes the caller's descriptor 3, and the descriptor spec holds only 0 to 2 (stdout and stderr go to a log in the scratch directory), so the server never holds the status descriptor.
4. Once the server log reports the port it listens on, it requests `/` on that port until the server answers, for at most 30 seconds. A status code below 500 is `ready` (a 404 shows the kernel booted). A status code of 500 or more, a status line it cannot parse, an early exit or no answer is `failed`. The reason is the first log line that mentions an error or exception, `no error in the server log` when there is none, or the unparsable status line.
5. It stops the server (SIGTERM, then SIGKILL after 5 seconds) and removes the scratch directory. It prints the server log to stdout, except in command mode after a `ready` boot, where the command's output is what the caller wants. Only then does it open descriptor 3, once, and write the status line, so nothing it started can inherit a duplicate of it. A descriptor 3 that the PHP runtime opened itself counts as missing: with CLI OPcache enabled, OPcache's lock (an unlinked file) takes the first free descriptor before the script runs, so the script checks the link count of what it opened (`fstat`) and refuses zero, which a caller's file, pipe or device never has. A caller's memfd (the build loop's channel) also has no link, so it is accepted when `/proc/self/fd/3` points to a `/memfd:` name and is not exactly OPcache's own lock (`/memfd:opcache_lock (deleted)`); a caller's memfd whose name only starts with `opcache_lock` is accepted.

Contract with the build loop: the status line is `{"status":"ready"}` or `{"status":"failed","reason":"..."}` on descriptor 3 only; stdout and stderr are the log (or the command's output). Without a command, the exit code is 0 exactly when `ready` was reported. In command mode, a `ready` boot exits with the command's status (124 when it was killed), and a `failed` boot exits 1. When descriptor 3 is not open, or the PHP runtime opened it itself (OPcache's lock), no status is written and the exit code is 1. The status is an `InstanceStatus` marked `#[NoDiscard]`, so the script cannot drop it. `tests/Application/InstanceTest.php` covers a clean boot, a broken kernel configuration, an unparsable status line, the server's descriptors (read from `/proc` while it runs, with a caller holding descriptors 3 to 14), leftovers in `$TMPDIR` and in the project tree, the missing descriptor (closed explicitly, since PHPUnit's own process holds Composer's bin proxy script on descriptor 3 and a child would inherit it), the descriptor OPcache takes when `opcache.enable_cli` is on (skipped where the extension is not loaded), a memfd as descriptor 3 (passed by a small Python helper, once named `status` and once `opcache_lock_status`), a pipe as descriptor 3, and in command mode the passed-through exit code, the suppressed and the printed server log, the closed descriptor 3 and the kill with 124.

How `bin/probe` works:

1. It takes one path, `tests/Probe/NAME.php` (letters, digits, `_`, `.` and `-`, an existing file); anything else prints the usage and exits 2.
2. It starts `unshare --net --map-root-user --pid --fork --kill-child`, which gives the run a new network namespace with no route out and a new PID namespace, brings loopback up (the instance needs it) and runs `php bin/instance -- <probe command>` in it. `bin/instance` is process 1 of that PID namespace, so when it exits the kernel kills every process the probe left behind, also one that started its own session, after a normal end, a failure or the 60-second kill. If the namespaces cannot be created, no status is reported and the probe fails; it never runs with the network open.
3. A name ending in `Test.php` runs as `vendor/bin/phpunit --no-configuration --bootstrap tests/bootstrap.php --do-not-record-test-run-history <file>`, anything else as `php <file>`. The probe sees `INTAKT_INSTANCE_URL` (the instance's base URL) and the scratch `DATABASE_URL`, cache, log and share variables.
4. The probe's output goes straight to stdout and stderr; `bin/probe` ends with the line `exit code: N` and exits with the probe's status. When the instance is not `ready` it prints the status line to stderr and exits non-zero, also when the probe itself would have exited 0.
5. The instance stops and removes its scratch directory as `bin/instance` does, so no process or file is left. `tests/Application/ProbeTest.php` covers a script, a PHPUnit test, a probe that reaches for the network (connecting to `192.0.2.1` fails with `Network is unreachable`, which only a namespace without a route gives; with a route it would time out), a refused path, the leftovers, a child the probe left running in its own session (after a probe that ends with 0, one that ends with 4, and one killed after its time with exit code 124; the child confirms that it runs by creating a marker file before the probe goes on, so a child that never started cannot pass as a killed one, and the kernel reaps every process of the PID namespace before `unshare` returns, so the check after the run is not a race), and an `unshare` that fails, where the probe never runs and `bin/probe` exits non-zero. The tests that need the namespaces are skipped on a host that refuses them, as the gate tests are.

## Upstream reproductions

`php bin/console intakt:upstream:run [directory]` (module `Core/Upstream`, plan task T26) runs every subfolder of `upstream/` (or of `directory`, relative to the project or absolute) as its own PHPUnit run: `vendor/bin/phpunit --no-configuration` with `vendor/autoload.php` as bootstrap, through Symfony Process, 300 seconds at most. A reproduction asserts the expected behavior, so a failing run means the bug is still there. Only a test that ran and asserted can show a fix, so the run also fails on an empty suite, a skipped or incomplete test and a test without assertions (`--fail-on-empty-test-suite`, `--fail-on-skipped`, `--fail-on-incomplete`, `--fail-on-risky`); a reproduction that is only a script, with no `*Test.php` file, is therefore reported as `still fails` (builder's choice: the design allows a script, but the runner cannot tell a fixed script from one that never checked anything). A run that reaches the timeout is stopped, reported as `still fails` with the output `Timed out after 300 seconds.`, and the next reproduction runs. When `directory` does not exist or cannot be read, or listing it fails (`glob` with `GLOB_ERR`), the command prints an error and exits with 2 without running anything, so a mistyped or unreadable folder never reads as a clean run. The command first prints the installed `symfony/ai-*` and `mcp/*` versions, then one line per reproduction (`passes` or `still fails`) and a summary. With `-v` the output of each failing run follows its line. The exit code is 1 when any reproduction still fails, otherwise 0. `tests/Core/Upstream/RunReproductionsCommandTest.php` covers a passing and a failing sample, a timeout followed by a further reproduction, a missing folder, an unreadable folder (skipped for the superuser, whom permission bits do not bind), a script-only folder and skipped, incomplete and assertion-free tests. The gates keep skipping `upstream/` (`Scope::EXCLUDED_PREFIXES`), so a failing reproduction never fails `bin/check`.

## Conventions

- **Logging** is structured (context arrays, no interpolated secrets) and passes through a redaction processor that strips tokens and API keys; the processor has its own tests.
- **Doctrine mapping** uses attributes with the `ORM` alias, and each module's entities and mapping live in that module.
- **Dependencies:** official Symfony and Doctrine packages first. Any other package needs a release within the last 12 months and a clean `composer audit`, and the owner approves it; a new official Symfony or Doctrine package is routine under the build loop's composer policy. The release rule applies to the packages Intakt requires directly. A package those pull in is judged together with its parent: it needs a clean `composer audit`, and an older release is accepted when the parent requires it and is itself current (for example `marc-mabe/php-enum` under `justinrainbow/json-schema`); list such packages under `## New dependencies` too.
- **Message handlers** use Intakt's `AsCommandHandler` and `AsQueryHandler` (in the platform module's `Contract`, with a `Bus` enum), which extend Symfony's `AsMessageHandler` and fix the bus; a PHPArkitect rule or guard test refuses `AsMessageHandler` anywhere else. They arrive with the first message handler (plan task T9).

## Persistence

SQLite by default (`DATABASE_URL` in `.env`). MySQL, MariaDB and PostgreSQL are supported targets; their CI jobs start with the first entity (see [the plan](../plan.md)).
