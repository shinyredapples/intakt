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
3. It runs `composer install --no-interaction --no-progress --no-scripts` in the snapshot (at most 600 seconds). Composer plugins the commit allows still run, because `symfony/runtime` writes `vendor/autoload_runtime.php` as a plugin and `bin/instance` needs that file; a plugin is the commit's own code, as is the code `bin/instance` boots, and it runs with the same allowlisted environment and scratch home (below). A failure is `failed` with `composer install failed: ` and Composer's error output.
4. Then it places the harness at `bin/instance` in the snapshot, so nothing the install wrote there (a Composer plugin of the commit, or a package binary when the commit sets `bin-dir` to `bin`) replaces it. A `bin/instance` the snapshot holds (also a symbolic link) is removed first, so writing never follows a link out of the snapshot; a `bin` that is a symbolic link or a file is `failed` without writing anything.
5. It runs `php bin/instance` through `sh -c 'exec "$@" 3>&1 1>&2'` (at most 180 seconds), so the status line of descriptor 3 arrives on standard output and the server log on standard error, where no log line can pass for the status.

Both commands get only an allowlist of the boot install's environment variables: `PATH`, `LANG`, `LC_ALL`, `LC_CTYPE`, `TZ`, `TMPDIR`, `COMPOSER_CACHE_DIR` and the proxy variables (`HTTP_PROXY`, `HTTPS_PROXY`, `NO_PROXY`, also in lower case). Every other variable, including those Dotenv loaded into `$_ENV`, `APP_ENV`, `APP_SECRET` and `COMPOSER_AUTH`, is unset, so the commit boots with the defaults of `bin/instance` and gets no token of the booting install from its environment. `HOME` and `COMPOSER_HOME` are not inherited either: both commands get one empty scratch directory beside the snapshot (the snapshot's path plus `-home`, mode 0700) as `HOME` and `COMPOSER_HOME`, removed with the snapshot. Without it Composer would load the booting install's `auth.json` (from `COMPOSER_HOME`, or from `~/.composer` or `~/.config/composer` under `HOME`) and a Composer plugin of the commit would read its tokens through Composer's config. The package cache is still shared when the install sets `COMPOSER_CACHE_DIR`; without it Composer caches inside the scratch home, so each boot downloads afresh.

What this does not do: the install and the commit's code run as the install's user with no filesystem isolation, so a plugin or `bin/instance` that computes the real home (for example through `posix_getpwuid`) can still read files there. The allowlist and the scratch home remove only what the environment and Composer hand over; keeping head code away from the install's files is the job sandbox of #340 (review job containers), not built yet.
6. A `ready` status with exit code 0 is `booted` (with or without `"mode":"worker"`); a `failed` status with a reason is `failed` with that reason; no status line, a status the contract of `bin/instance` does not define, or `ready` with another exit code is `crashed`.

Tests: `tests/Plugin/LocalGitForge/InstanceBootTest.php` (each outcome on a scratch repository whose stub `bin/instance` reports what the commit holds, main's harness winning over the head's, a Composer plugin of the head that neither sees a secret of the boot install's environment, nor the token of an `auth.json` in its `COMPOSER_HOME` or under its `HOME`, nor replaces the harness, the scratch home removed after the boot, a package binary named `instance`, a secret of the boot install that does not reach the harness, the link cases, the crash cases and the removed snapshot) and `tests/Plugin/LocalGitForge/BootHeadHandlersTest.php` (which harness each query uses).
