# [FrameworkBundle] `debug:config` fails when the cached container is stale

Filed as pull request [symfony/symfony#68310](https://github.com/symfony/symfony/pull/68310) against 6.4 on 2026-11-20 (see `## Upstream status`). Found while building #2901: `bin/console debug:config framework asset_mapper` failed with `Can not change config enabled because it has already been read.` (`FrameworkExtension.php:757`). Recorded by #3107. Versions: `symfony/framework-bundle` v8.2.0-BETA2 (Intakt's), PHP 8.5.12.

## What happens

`ConfigDebugCommand::compileContainer()` clones the kernel and boots the copy. When the cached container is stale, `boot()` builds the container, which loads every bundle extension; the command then calls `buildContainer()` on the same copy, so the same extension instances are loaded again and `FrameworkExtension::load()` throws in `writeConfigEnabled()`. It is not about AssetMapper: any `debug:config <name>` fails, with or without a path.

On 8.2 the cache is stale now and then without any change, since symfony/symfony 73784a8d00 ("[Config] Detect changes made in the same second as a cache was built"): in Intakt the first run after a fresh install (no `config/reference.php`) failed, later runs passed.

## Reproduction

`reproduce.php` builds its own app in a fresh folder under `sys_get_temp_dir()`: `console.php` is a kernel with FrameworkBundle only, whose config file (`default_locale: zu_ZA`), cache and logs live in that folder. The script runs `cache:warmup`, dates the config file one minute in the future, runs `debug:config framework default_locale` and removes the folder. Both commands run through Symfony Process with `APP_DEBUG=1` set for the child, so a caller's `APP_DEBUG=0` cannot turn debug mode off for them (with debug off, Symfony does not check whether the cached container is fresh). It passes only when the command exits with 0 and prints `zu_ZA`; on a failure it prints the line with the exception message and the exit code. At Intakt's pinned versions it prints:

```
exception: [critical] Error thrown while running command "debug:config framework default_locale". Message: "Can not change config enabled because it has already been read."
still fails (exit 1)
```

On 2026-11-20, before this script, the same steps were run by hand on fresh `symfony/skeleton` apps with `symfony/asset-mapper` (PHP 8.5.12, `composer:2` image), after `touch -d '+1 hour' config/services.yaml`: 6.4.50 (framework-bundle 6.4.49), 7.4.23, 8.1.11, 8.2.0-BETA2 and `8.2.x-dev` (framework-bundle d1f6bdf) exit 1 with the message for `debug:config framework`, `framework secrets` and `framework asset_mapper`; with a fresh cache all exit 0. On the 8.2.0-BETA2 skeleton, `rm -rf var config/reference.php && bin/console debug:config framework secrets` failed in 5 of 10 and 2 of 10 runs (two batches).

## Expected

`debug:config` prints the configuration whether the cached container is fresh or stale.

## Effect in Intakt

None at runtime: only the `debug:config` command fails, and a second run passes once the cache is fresh.

## Upstream status

- **Checked:** 2026-11-22, run as a regular user: `reproduce.php` from its folder (`php -d auto_prepend_file=vendor/autoload.php reproduce.php`) at Intakt's pinned `symfony/framework-bundle` v8.2.0-BETA2 (48780dea32, from `composer.lock`), PHP 8.5.12: `still fails (exit 1)` with the message above, also with `APP_DEBUG=0` set in the caller; no folder of the script is left in the temporary directory. `php bin/console intakt:upstream:run` on a folder holding a copy of this one, without and with `APP_DEBUG=0` in the caller: `still fails  framework-debug-config-stale-container`, with `-v` the two lines above. With the `ConfigDebugCommand.php` line of `fix.patch` added to a scratch copy of Intakt's `vendor/` (restored afterwards), the script prints `debug:config framework default_locale printed zu_ZA` and `REPRODUCTION PASSES`, exit code 0.
- **Pull requests and commits:** none found that fix it (checked 2026-11-20: issues and pull requests, open and closed, `"has already been read"`, `"Can not change config enabled"`, `debug:config asset_mapper`, `ConfigDebugCommand compileContainer`, `writeConfigEnabled`, `readConfigEnabled`, `configsEnabled`, `debug:config stale container`, all `debug:config` titles). Related, not the same: #29019 (closed, 2018), #65481 (merged, compiled container in `findExtension()`). On 2026-11-22 the commits on `src/Symfony/Bundle/FrameworkBundle/Command/ConfigDebugCommand.php` since 2026-10-01 on `6.4` (head d3ef71457a, none), `7.4` (c8778bb100), `8.1` (46cb370c72) and `8.2` (2894e4eeea) are 792b634b05 (JSON and YAML output of `debug:config`), d5d4453430 (escaping in console output), and on `8.2` also e82dd6a11e and 32cbb83c1e; none changes how `compileContainer()` boots the copy. The newest release is still v8.2.0-BETA2 (2026-11-13).
- **Form and branch:** a fix pull request (Symfony takes fix pull requests directly) against `6.4`, the lowest maintained branch with the bug. Source: the symfony/symfony pull request template and contributing guide.
- **Pull request as filed:** `PR.md` is the text of symfony/symfony#68310 as read on 2026-11-22 and `fix.patch` its commit (`gh pr diff 68310 --patch`): the command initializes the bundles of the kernel copy again after booting it, and `testDumpWhenTheCachedContainerIsStale`.
- **State on 2026-11-22:** open, labels `Bug`, `FrameworkBundle` and `Status: Needs Review`, milestone 6.4, no review and no comment, all 15 checks green (read with `gh pr view 68310 -R symfony/symfony`).
- **Decision:** `filed as pull request` [symfony/symfony#68310](https://github.com/symfony/symfony/pull/68310) against 6.4 on 2026-11-20 (the owner filed it, as recorded on #3107 on 2026-11-21); next: watch its review and CI.
