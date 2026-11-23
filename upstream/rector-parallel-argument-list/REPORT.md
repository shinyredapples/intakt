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
- **Development branch:** still fails at `rector/rector` `dev-main` 95b536f (built from `rectorphp/rector-src` `main` e9fc2fd5db52, 2026-11-20), and at the newest release 2.8.5 (`rector-src` tag 2.8.5, 9f30de4a6caa), each installed in a scratch copy outside Intakt: `reproduce.php` exits with 1 and prints the same `posix_spawn() failed: Argument list too long` (run 2026-11-21). `src/Parallel/Command/WorkerCommandLineFactory.php` on `main` still appends every `source` path and returns `implode(' ', $workerCommandArray)`.
- **Existing issue or pull request:** rectorphp/rector#9591 (https://github.com/rectorphp/rector/issues/9591) shows the same error on a large codebase; it was closed on 2026-01-11 after the workaround of running Rector in batches of about 1,000 files, with no fix and no named cause, and is locked. No other issue matches.
- **Pull requests and commits:** none found (checked 2026-11-21). Pull requests (open, merged and closed) in `rectorphp/rector-src` and `rectorphp/rector` for `Argument list too long` and `posix_spawn` found none; for `WorkerCommandLineFactory` they found only merged changes that keep the single command string (rectorphp/rector-src#8812, #8401, #5398, #4813, #1637, #1620). Of the 116 `rector-src` `main` commits since 2.8.3 (8323e93e55a4), two touch `src/Parallel/`: 392637a285ed (#8957, skip reporting) and 56f92f70f806 (#8917, JSON changed files); neither changes the worker command line. The release notes of 2.8.4 and 2.8.5 name no parallel change.
- **Checked:** 2026-11-21. Ran against `rectorphp/rector-src` `main` e9fc2fd5db52 (`rector/rector` `dev-main` 95b536f) and the newest release 2.8.5 (9f30de4a6caa): both still fail. Duplicate search: GitHub search of issues and pull requests (open, merged and closed) in `rectorphp/rector` and `rectorphp/rector-src` for `"Argument list too long"`, `posix_spawn` and `WorkerCommandLineFactory`, and the `rector-src` `main` commits on `src/Parallel` since 2.8.3.
- **Form (the original draft, superseded by the Decision below):** a new issue in `rectorphp/rector` with its `Bug Report` template, text in `ISSUE.md`: #9591 is closed and locked, so no comment can go there, and the fix (sending the paths to the workers another way) changes how the workers start, a choice for the maintainers. Pull requests target `rectorphp/rector-src` `main` (`CONTRIBUTING.md` of both repositories).
- **Decision:** `filed as pull request` https://github.com/rectorphp/rector-src/pull/9012 (2026-11-21, the owner's OK), "[Parallel] Pass the command-line paths to the workers in a file, so many paths fit", naming rectorphp/rector#9591. The form changed from the issue draft to a pull request: Rector's contributing guide asks for pull requests, and #9591 is closed and locked. `PR.md` holds the pull request's title and body as posted; `ISSUE.md` stays as the draft the report started from, and it was not posted.
- **Status:** `declined upstream`. Checked 2026-11-22: rectorphp/rector-src#9012 was closed without merging on 2026-11-21 at 14:06 UTC; the maintainer wrote that the change adds cross-platform uncertainty for a narrow case and that running Rector in batches is the documented workaround. Nothing more is posted upstream. Intakt keeps its own workaround: the `rector` gate passes its files in a JSON file named by `INTAKT_RECTOR_PATHS_FILE` (see "Effect in Intakt").
