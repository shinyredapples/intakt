# Boot step

The boot step boots one commit of a project once with `bin/instance` ([Instance and probes](instance-and-probes.md)) and reports the outcome (plan task T58, part 1). It is the harness side of the boot gate in [Reviews](../design/05-architecture/07-reviews.md): nothing calls it before a review yet, main's result is not cached yet, and a failing head is not sent back yet; those parts come later.

## Contract

In `src/Core/Review/Contract/`:

- `BootAt`: the repository and base (`ChangeSource`) and the exact commit (`CommitId`) to boot.
- `BootHarness`: a query that boots `BootAt` with the `bin/instance` that commit carries itself, as main is booted.
- `BootHarnessAtMain`: a query that boots the head in `BootAt` with the `bin/instance` of main's commit, so a head that changes the harness does not certify itself ([Merge](../design/05-architecture/08-merge.md), "Harness from main").
- `BootOutcome`: the answer to both, a `BootState` (`booted`, `failed`, `crashed`) and a reason (empty when booted).

`failed` is the commit's own result: its harness reported `{"status":"failed","reason":"..."}`, or `composer install` failed in it. `crashed` is the boot step's: no status could be read, or the step itself failed (the repository or the commit is missing, main has no `bin/instance`, git or a process could not run, or a process passed its time limit). A crash is an outcome, never an exception, so a crashing boot step can never stop the review it gates.

## How a commit boots

The handlers `BootHarnessHandler` and `BootHarnessAtMainHandler` (query bus) live in the local git forge, in `src/Plugin/LocalGitForge/UseCase/BootHead/`, and both call `InstanceBoot`:

1. It reads `bin/instance` at the harness commit (the commit itself for `BootHarness`, main's for `BootHarnessAtMain`).
2. It takes a detached worktree of the commit in the temp directory, as a review snapshot is taken ([Forge contract](forge-contract.md)), and removes it after the boot, whatever the outcome.
3. It places the harness at `bin/instance` in the snapshot. A `bin/instance` the commit holds (also a symbolic link) is removed first, so writing never follows a link out of the snapshot; a `bin` that is a symbolic link or a file is `failed` without writing anything.
4. It runs `composer install --no-interaction --no-progress --no-scripts` in the snapshot (at most 600 seconds). A failure is `failed` with `composer install failed: ` and Composer's error output.
5. It runs `php bin/instance` through `sh -c 'exec "$@" 3>&1 1>&2'` (at most 180 seconds), so the status line of descriptor 3 arrives on standard output and the server log on standard error, where no log line can pass for the status. `APP_ENV` and `APP_SECRET` are unset for both commands, so the commit boots with the defaults of `bin/instance`, not the settings of the install that boots it.
6. A `ready` status with exit code 0 is `booted` (with or without `"mode":"worker"`); a `failed` status with a reason is `failed` with that reason; no status line, a status the contract of `bin/instance` does not define, or `ready` with another exit code is `crashed`.

Tests: `tests/Plugin/LocalGitForge/InstanceBootTest.php` (each outcome on a scratch repository whose stub `bin/instance` reports what the commit holds, main's harness winning over the head's, the link cases, the crash cases and the removed snapshot) and `tests/Plugin/LocalGitForge/BootHeadHandlersTest.php` (which harness each query uses).
