Filed as symfony/symfony#68310 (https://github.com/symfony/symfony/pull/68310) on 2026-11-20 against 6.4. The text below is the pull request as filed, read on 2026-11-22; `fix.patch` holds its commit (`gh pr diff 68310 --patch`).

Commit: 5dc0e556a2 [FrameworkBundle] Fix `debug:config` when booting the kernel copy rebuilds the container

Repository: symfony/symfony, base branch 6.4

Title: [FrameworkBundle] Fix `debug:config` when booting the kernel copy rebuilds the container

| Q             | A
| ------------- | ---
| Branch?       | 6.4
| Bug fix?      | yes
| New feature?  | no
| Deprecations? | no
| Issues        | -
| License       | MIT

`debug:config <name>` fails in debug mode when the cached container is stale when the command runs:

```
$ touch -d '+1 hour' config/services.yaml
$ php bin/console debug:config framework

In FrameworkExtension.php line ...:
  Can not change config enabled because it has already been read.
```

Measured on a fresh `symfony/skeleton` with `symfony/asset-mapper`: 6.4.50 (framework-bundle 6.4.49), 7.4.23, 8.1.11, 8.2.0-BETA2 and `8.2.x-dev` all exit 1 with this message, for `debug:config framework`, `debug:config framework secrets` and `debug:config framework asset_mapper`. With a fresh cache, all of them exit 0.

`ConfigDebugCommand::compileContainer()` clones the kernel and boots the copy. When the cached container is stale, `boot()` builds the container, which loads every bundle extension. The command then calls `buildContainer()` on the same copy, so the same extension instances are loaded a second time, and `FrameworkExtension::load()` refuses that in `writeConfigEnabled()`.

On 8.2, since 73784a8d00 ("[Config] Detect changes made in the same second as a cache was built"), this also happens without changing any file: on the 8.2.0-BETA2 skeleton, `rm -rf var config/reference.php && php bin/console debug:config framework secrets` failed in 5 of 10 runs, and in 2 of 10 in a second batch. Forcing that state (boot the kernel on an empty `var/`, give `config/services.yaml` the mtime of the cached container without changing its content, then run `debug:config framework secrets` with that kernel) failed 3 of 3 times on 8.2.0-BETA2 and exited 0 on 8.1.11.

The fix initializes the bundles of the copy again after booting it, as `BuildDebugContainerTrait::getContainerBuilder()` already does before `buildContainer()`, so the container the command compiles gets fresh extension instances. With it, on the 8.2.0-BETA2 skeleton, the future-dated case exits 0, the cold cache exits 0 in 10 of 10 runs and the forced same-second case in 3 of 3.

With a stale cache the copy still builds the container twice (once in `boot()`, once in the command); not booting the copy would avoid that, but it would skip the bundles' `boot()`, so I left it. Happy to change it if you prefer.

Tests: `testDumpWhenTheCachedContainerIsStale` dates the test app's `config.yml` 10 seconds in the future, runs `debug:config framework secret` and restores the mtime. On 6.4, 7.4, 8.1 and 8.2 it fails without the fix (`LogicException: Can not change config enabled because it has already been read.`) and passes with it, and `ConfigDebugCommandTest` and `ConfigDumpReferenceCommandTest` pass (6.4: 41 and 16 tests, 7.4 and 8.1: 44 and 20, 8.2: 52 and 21; monorepo install, PHP 8.4). The FrameworkBundle suite passes with low and high dependencies set up as `unit-tests.yml` does for 6.4 (PHP 8.4): 1461 tests with `--prefer-lowest --prefer-stable`, 1398 with the highest ones; in the lowest install the new test also fails without the fix. php-cs-fixer with the repository config reports nothing on the two files on all four branches.
