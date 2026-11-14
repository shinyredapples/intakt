# Boot step

The boot step boots one commit of a project once with `bin/instance` ([Instance and probes](instance-and-probes.md)) and reports the outcome (plan task T58, part 1). It is the harness side of the boot gate in [Reviews](../design/05-architecture/07-reviews.md). `BootGate` ([below](#the-gate-decision)) decides from it whether a head goes back, but nothing calls the gate before a review yet, so no head is sent back yet; acting on the decision in the watcher comes later.

## Contract

In `src/Core/Review/Contract/`:

- `BootAt`: the repository and base (`ChangeSource`) and the exact commit (`CommitId`) to boot.
- `BootHarness`: a query that boots `BootAt` with the `bin/instance` that commit carries itself, as main is booted.
- `BootHarnessAtMain`: a query that boots the head in `BootAt` with the `bin/instance` of main's commit, so a head that changes the harness does not certify itself ([Merge](../design/05-architecture/08-merge.md), "Harness from main").
- `BootOutcome`: the answer to both, a `BootState` (`booted`, `failed`, `crashed`) and a reason (empty when booted).

`failed` is the commit's own result: its harness reported `{"status":"failed","reason":"..."}`, or `composer install` failed in it. `crashed` is the boot step's: no status could be read, or the step itself failed (the repository or the commit is missing, main has no `bin/instance`, git or a process could not run, or a process passed its time limit). A crash is an outcome, never an exception, so a crashing boot step can never stop the review it gates.

## Main's cached result

`BootResults::ofMain(BootKey, ChangeSource)` in `src/Core/Watcher/UseCase/WatchPullRequests/` (part 2) answers main's boot result from the table `watcher_boot_result` and boots only on a miss. `BootKey` is the project, main's commit and a tool fingerprint (a string the caller passes; nothing computes one yet), so main's result is reused while both stay the same and recomputed when either changes. On a miss it asks the query bus with `BootHarness`, stores the row of a `BootRecord` (the state, the reason and the moment, from `ClockInterface`) and appends the ledger event `boot_result_computed` (project, main commit, tool fingerprint, state, reason); a cache hit appends nothing. A `crashed` outcome is returned but not stored, because it is the boot step's fault and not main's, so the next ask boots again; a query bus that throws is turned into a `crashed` outcome with the exception's class and message. Before the reason is stored, logged in the ledger or returned, bytes that are not UTF-8 are replaced with `?` and it is redacted through the `Redactor` as a log line is, so a token in a tool's output or an exception message is neither stored nor shown.

The record is read through the entity manager but written with one insert on the connection, inside a savepoint, never with a flush: the default entity manager is the ledger's, and a failed flush would close it. When two workers miss the same key at once, the one whose insert loses (a unique constraint violation) still returns its own outcome. Nothing in `ofMain` throws: a cache that cannot be read is treated as a miss, a result that cannot be stored or a ledger line that cannot be written is logged as an error, and the computed outcome is returned in every case. `BootResults::ofHead(BootAt, CommitId main)` boots a head with `BootHarnessAtMain` through the same dispatch, crash and scrubbing steps, and stores and records nothing.

Tests: `tests/Core/Watcher/UseCase/WatchPullRequests/BootResultsTest.php` (`persistence` group) and `tests/Core/Watcher/Domain/BootKeyTest.php`.

## The gate decision

`BootGate::admit(WatchedProject, HeadDecision)` in `src/Core/Watcher/UseCase/WatchPullRequests/` takes the decision of a head whose CI is green and answers the decision the head gets after its boot. It runs for every watched project:

1. it asks the query bus for `MainCommit(ChangeSource)`, the commit the base branch points at, answered by the local git forge's `MainCommitHandler` (`git show-ref` of the base branch in the project's `repository` path);
2. it asks `BootResults::ofMain` for main's result under the `BootKey` of the project, that commit and the constant `BootGate::TOOL_FINGERPRINT` (nothing computes a fingerprint of the boot tools yet, so one constant stands for them);
3. only when main is `booted`, it boots the head with `BootResults::ofHead`.

| main | head | decision |
| --- | --- | --- |
| booted | failed | `HeadDecision::sentBackFor(FailedBoot)`: `HeadAdmission::FailedBoot` with the redacted reason in `FailedBoot`; it starts no round, like red CI |
| booted | booted or crashed | the green decision unchanged: the round may start |
| failed or crashed | not booted | the green decision unchanged: the gate is off for the head |

A project without `bin/instance` has the gate off: main's boot crashes because main has no harness (before any `composer install`), and a crash is not stored, so each ask reads main's tree for `bin/instance` again. Any `Throwable` from the gate (also a `MainCommit` answer that is not a commit) is logged as the error `The boot gate failed, so the review starts without it.` and the green decision is returned, so a crashing gate never stops the review. `WatcherLedger` writes a `FailedBoot` decision as `head_red`, as it does a red head.

Nothing calls `BootGate` yet: the watcher's tick does not boot heads, so no head is sent back and no stale mark is sent; that wiring is a later part of plan task T58.

Tests: `tests/Core/Watcher/UseCase/WatchPullRequests/BootGateTest.php` (`persistence` group), `tests/Core/Watcher/Domain/HeadDecisionTest.php` and the main commit case of `tests/Plugin/LocalGitForge/BootHeadHandlersTest.php`.

## How a commit boots

The handlers `BootHarnessHandler` and `BootHarnessAtMainHandler` (query bus) live in the local git forge, in `src/Plugin/LocalGitForge/UseCase/BootHead/`, and both call `InstanceBoot`:

1. It reads `bin/instance` at the harness commit (the commit itself for `BootHarness`, main's for `BootHarnessAtMain`). A harness the forge names as binary (a NUL byte anywhere) is `crashed` with `bin/instance is a binary file of <n> bytes, not a harness.`, because the forge hands over its size, not its bytes.
2. It takes a detached worktree of the commit in the temp directory, as a review snapshot is taken ([Forge contract](forge-contract.md)), and removes it after the boot, whatever the outcome.
3. It runs `composer install --no-interaction --no-progress --no-scripts` in the snapshot (at most 600 seconds). Composer plugins the commit allows still run, because `symfony/runtime` writes `vendor/autoload_runtime.php` as a plugin and `bin/instance` needs that file; a plugin is the commit's own code, as is the code `bin/instance` boots, and it runs with the same allowlisted environment and scratch home (below). When `composer` cannot be found or executed (exit code 127 or 126), the outcome is `crashed` with `composer could not run: ` and the shell's error output, because that is the boot step's fault, not the commit's. Any other failure is `failed` with `composer install failed: ` and Composer's error output.
4. Then it places the harness at `bin/instance` in the snapshot, so nothing the install wrote there (a Composer plugin of the commit, or a package binary when the commit sets `bin-dir` to `bin`) replaces it. A `bin/instance` the snapshot holds (also a symbolic link) is removed first, so writing never follows a link out of the snapshot; a `bin` that is a symbolic link or a file is `failed` without writing anything.
5. It runs `php bin/instance` through `sh -c 'exec "$@" 3>&1 1>&2'` (at most 180 seconds), so the status line of descriptor 3 arrives on standard output and the server log on standard error, where no log line can pass for the status.

Both commands get only an allowlist of the boot install's environment variables: `PATH`, `LANG`, `LC_ALL`, `LC_CTYPE`, `TZ`, `TMPDIR`, `COMPOSER_CACHE_DIR` and the proxy variables (`HTTP_PROXY`, `HTTPS_PROXY`, `NO_PROXY`, also in lower case). Every other variable, including those Dotenv loaded into `$_ENV`, `APP_ENV`, `APP_SECRET` and `COMPOSER_AUTH`, is unset, so the commit boots with the defaults of `bin/instance` and gets no token of the booting install from its environment. `HOME` and `COMPOSER_HOME` are not inherited either: both commands get one empty scratch directory beside the snapshot (the snapshot's path plus `-home`, mode 0700) as `HOME` and `COMPOSER_HOME`, removed with the snapshot. Without it Composer would load the booting install's `auth.json` (from `COMPOSER_HOME`, or from `~/.composer` or `~/.config/composer` under `HOME`) and a Composer plugin of the commit would read its tokens through Composer's config. The package cache is still shared when the install sets `COMPOSER_CACHE_DIR`; without it Composer caches inside the scratch home, so each boot downloads afresh.

6. Exactly `{"status":"ready"}` or `{"status":"ready","mode":"worker"}` (the two lines `bin/probe` accepts) with exit code 0 is `booted`; `{"status":"failed","reason":"..."}` or `{"status":"failed","mode":"worker","reason":"..."}` with a non-empty reason is `failed` with that reason; no status line, more than one line, any other status, key or mode (also `ready` with an undefined mode or an extra key), or `ready` with another exit code is `crashed`.

What this does not do: the install and the commit's code run as the install's user with no filesystem isolation, so a plugin or `bin/instance` that computes the real home (for example through `posix_getpwuid`) can still read files there. The allowlist and the scratch home remove only what the environment and Composer hand over; keeping head code away from the install's files is the job sandbox of #340 (review job containers), not built yet.

Tests: `tests/Plugin/LocalGitForge/InstanceBootTest.php` (each outcome on a scratch repository whose stub `bin/instance` reports what the commit holds, main's harness winning over the head's, a Composer plugin of the head that neither sees a secret of the boot install's environment, nor the token of an `auth.json` in its `COMPOSER_HOME` or under its `HOME`, nor replaces the harness, the scratch home removed after the boot, a package binary named `instance`, a secret of the boot install that does not reach the harness, the link cases, the crash cases (also a binary harness, a missing or non-executable `composer`, beside a `composer` that runs and fails, which is `failed`) and the removed snapshot) and `tests/Plugin/LocalGitForge/BootHeadHandlersTest.php` (which harness each query uses, and the commit `MainCommitHandler` answers).
