# `debug:config` fails when the cached container is stale

Found while building #2901: `bin/console debug:config framework asset_mapper` failed with `Can not change config enabled because it has already been read.` (`FrameworkExtension.php:757`).

## What happens

`ConfigDebugCommand::compileContainer()` clones the kernel and boots the copy. When the cached container is stale, `boot()` builds the container, which loads every bundle extension; the command then calls `buildContainer()` on the same copy, so the same extension instances are loaded again and `FrameworkExtension::load()` throws in `writeConfigEnabled()`. It is not about AssetMapper: any `debug:config <name>` fails, with or without a path.

On 8.2 the cache is stale now and then without any change, since symfony/symfony 73784a8d00 ("[Config] Detect changes made in the same second as a cache was built"): in Intakt the first run after a fresh install (no `config/reference.php`) failed, later runs passed.

## Reproduction

`reproduce.php` dates `config/services.yaml` one minute in the future, runs `debug:config framework secret` and restores the mtime. Not yet run through `intakt:upstream:run`; the same steps were run by hand on the skeletons below.

Measured on fresh `symfony/skeleton` apps with `symfony/asset-mapper` (PHP 8.5.12, Docker `composer:2` on CT 135), after `touch -d '+1 hour' config/services.yaml`: 6.4.50 (framework-bundle 6.4.49), 7.4.23, 8.1.11, 8.2.0-BETA2 and `8.2.x-dev` (framework-bundle d1f6bdf) exit 1 with the message for `debug:config framework`, `framework secrets` and `framework asset_mapper`. With a fresh cache all exit 0. On 8.2.0-BETA2, `rm -rf var config/reference.php` before `debug:config framework secrets` failed in 1 of 3 runs.

`fix.patch` (against 6.4 d3ef71457a): initialize the bundles of the copy again after booting it, and `testDumpWhenTheCachedContainerIsStale`, which fails without the fix and passes with it; `ConfigDebugCommandTest` (41) and `ConfigDumpReferenceCommandTest` (16) pass. The pull request text is prepared outside the repository.

## Upstream status

- **Checked:** 2026-11-20, symfony/symfony default branch `8.2` at 2894e4eeea9e, newest releases v8.2.0-BETA2, v8.1.11, v7.4.23, v6.4.50; all affected. Duplicate search (issues and pull requests, open and closed): `"has already been read"`, `"Can not change config enabled"`, `debug:config asset_mapper`, `ConfigDebugCommand compileContainer`, `writeConfigEnabled`, `readConfigEnabled`, `configsEnabled`, `debug:config stale container`, all `debug:config` titles, open pull requests `debug:config`: none found. Related, not the same: #29019 (closed, 2018), #65481 (merged, compiled container in `findExtension()`).
- **Form:** fix pull request (Symfony takes fix pull requests directly), base `6.4`, the lowest maintained branch with the bug (`END_OF_MAINTENANCE` 11/2026 in the 6.4 `Kernel`).
- **Fix pull request:** prepared, not complete: the low-deps run and php-cs-fixer are still to run.
- **Decision:** recheck needed before filing (low-deps, php-cs-fixer, merge-up to 7.4 and 8.2 not yet measured).
