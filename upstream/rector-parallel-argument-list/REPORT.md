# Rector's parallel workers fail to start on a long list of command-line paths

Versions: `rector/rector` 2.8.3 (Intakt's lock); PHP 8.5.12; Linux (`ARG_MAX` 2097152, a single argument string capped at 128 KiB).

## What happens

`rector process` with parallel processing and many paths on its command line fails before it checks a file:

```
In Process.php line 205:
  Unable to launch a new process: proc_open(): posix_spawn() failed: Argument
   list too long
```

`WorkerCommandLineFactory::create()` (`src/Parallel/Command/WorkerCommandLineFactory.php`) appends every path given as the `source` argument to the worker's command and joins it into one string (`implode(' ', $workerCommandArray)`), which is started through `/bin/sh -c`. Linux limits one argument string to 128 KiB (`MAX_ARG_STRLEN`), so once the paths pass that size no worker starts, although the main process itself accepted the same paths (its arguments are separate strings, each far below the limit). `reproduce.php` writes 1,000 empty classes under a long folder (264,889 bytes of paths) and runs Rector on them with `withParallel()`.

## Expected

The run checks every file, as it does without `withParallel()` or with fewer paths: for example the workers could receive the file list through the same TCP connection that already sends them their file chunks, instead of on their command line.

## Effect in Intakt

The `rector` gate passed every selected file on Rector's command line; a full run over the repository (2,513 paths) failed on main once more files were added (#3129). The gate now writes the paths to a JSON file and passes its absolute path in `INTAKT_RECTOR_PATHS_FILE`; `config/gates/rector.php` reads it with `withPaths()`, which never reaches the worker command line. Once this passes, the gate can pass its files on the command line again.

## Upstream status

- **Pinned versions:** still fails at `rector/rector` 2.8.3: `reproduce.php` exits with 1 and prints the error above (run 2026-11-21).
- **Development branch:** not run; the build sandbox reaches no upstream source beyond the locked packages.
- **Existing issue or pull request:** not searched; the build sandbox has no access to GitHub's search.
- **Pull requests and commits:** none found (checked 2026-11-21), but only the locked release was read; upstream's pull requests, commits and issues were not searched.
- **Decision:** recheck needed before filing: run the duplicate search (rector/rector issues and pull requests for `Argument list too long`, `posix_spawn`, `WorkerCommandLineFactory`) and the reproduction on the development branch first. Nothing is filed.
