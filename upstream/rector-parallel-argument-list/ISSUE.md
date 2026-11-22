Draft, not posted: the report went upstream as pull request rectorphp/rector-src#9012 instead (2026-11-21), which was closed without merging. Its posted text is in `PR.md`; see `REPORT.md`.

Repository: `rectorphp/rector`
Template: Bug Report
Title: Parallel workers fail to start with "Argument list too long" when many paths are passed on the command line

# Bug Report

| Subject        | Details                                                                 |
| :------------- | :---------------------------------------------------------------------- |
| Rector version | 2.8.5, also `dev-main` (`rector-src` e9fc2fd5db52); first seen on 2.8.3 |

`rector process` with `withParallel()` fails before it checks a file when the paths given as `source` together pass 128 KiB:

```
In Process.php line 205:
  Unable to launch a new process: proc_open(): posix_spawn() failed: Argument
   list too long
```

`WorkerCommandLineFactory::create()` appends every `source` path to the worker's command and returns it as one string (`implode(' ', $workerCommandArray)`), which is started through `/bin/sh -c`. Linux limits one argument string to 128 KiB (`MAX_ARG_STRLEN`, independent of `ARG_MAX`), so no worker can start, although the main process accepted the same paths as separate arguments. Lowering `maxNumberOfProcess` or `jobSize` does not help, since every worker gets the whole list. This is likely the cause of #9591, closed with the workaround of running Rector in batches.

## Minimal PHP Code Causing Issue

The online demo takes one file, so here is a shell reproduction (PHP 8.5, Linux):

```bash
composer require --dev rector/rector:2.8.5
dir=src/$(printf 'a_folder_with_a_long_name/%.0s' 1 2 3 4 5)
mkdir -p "$dir"
for i in $(seq 0 999); do printf '<?php\n\nfinal class Clean%d\n{\n}\n' $i > "$dir/Clean$i.php"; done
printf '<?php\n\nreturn Rector\\Config\\RectorConfig::configure()->withParallel()->withPreparedSets(deadCode: true);\n' > rector.php

vendor/bin/rector process --dry-run --no-progress-bar "$dir"*.php   # fails with the error above
vendor/bin/rector process --dry-run --no-progress-bar src           # same files: [OK] Rector is done!
```

## Expected Behaviour

The run checks every file, as it does with the folder as `source`, without `withParallel()`, or with fewer paths. For example, the workers could receive the file list through the TCP connection that already sends them their file chunks, instead of on their command line.
