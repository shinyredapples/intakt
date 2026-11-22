Repository: `rectorphp/rector-src`, base `main`. Posted as https://github.com/rectorphp/rector-src/pull/9012 on 2026-11-21 at 13:42 UTC; the text below is its body as posted. Closed without merging on 2026-11-21 at 14:06 UTC (see `REPORT.md`). `ISSUE.md` is the issue draft the report started from.

Title: [Parallel] Pass the command-line paths to the workers in a file, so many paths fit

---

Fixes the error from rectorphp/rector#9591 (closed as an OS limit, workaround: batching).

With parallel processing, `WorkerCommandLineFactory` puts every path given on the command line into each worker's command, a single string run through `/bin/sh -c`. Linux limits one argument string to 128 KiB, so once the paths pass that size no worker starts:

```
Unable to launch a new process: proc_open(): posix_spawn() failed: Argument list too long
```

The main process accepts the same paths, as each of its arguments is a separate string.

Measured on Linux 7.0, PHP 8.5.12, `rector/rector` 2.8.5 and rector-src `main` (e9fc2fd), with `withParallel()` and empty classes under a long folder:

| Paths on the command line | Result |
|---|---|
| 650 files, 122,739 bytes | passes |
| 700 files, 132,189 bytes | fails with the error above |
| 1,000 files, 188,889 bytes, this branch | passes |

**Change:** when paths are given, the main process writes them to a JSON temp file (`WorkerPathsFile`) and passes it to the workers as `--paths-file`; `WorkerCommand` reads it into the `source` argument before building its configuration, so the workers see the same paths as before. With no command-line paths nothing changes.

The file is removed when the run ends, on shutdown, and, when ext-pcntl is loaded, on SIGINT/SIGTERM: the handlers remove it and exit with 130/143. They are installed only for the parallel run and the previous handlers are restored afterwards; ext-pcntl stays optional.

Interrupting a 1,000-path run 1 s after start (`kill -INT` / `kill -TERM` to the main process):

| | exit code | paths files left |
|---|---|---|
| without the signal handlers, SIGINT / SIGTERM | 130 / 143 | 1 / 1 |
| this branch, SIGINT / SIGTERM | 130 / 143 | 0 / 0 |

Checked:

- New `WorkerCommandLineFactoryTest::testPathsGoInPathsFile` (1,000 paths, ~260 KiB): fails without the change, passes with it. The existing cases now expect no `'src'` on the worker line.
- New `WorkerPathsFileTest`: the file holds the paths, `remove()` deletes it and can run twice, and the previous SIGINT/SIGTERM handlers and async-signal setting come back after `remove()`.
- In the 1,000-file run, all 12 workers read all 1,000 paths (temporary debug output, not committed).
- `fastunit tests rules-tests utils/phpstan/tests` on PHP 8.4 and 8.5, ECS, PHPStan, `bin/rector process`, `swiss-knife` finalize/commented-code, and the 14 e2e directories from `e2e.yaml`: all pass.

Open points:

- Not run on Windows.
- Without ext-pcntl, or on SIGKILL, the temp file is not removed; I did not test these cases.
- The worker command line no longer shows the paths, e.g. in `ps`.
