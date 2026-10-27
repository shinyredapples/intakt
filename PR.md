feat(instance): add bin/instance to boot Intakt offline and print a JSON status line

`bin/instance` boots Intakt with the plain Symfony runtime on PHP's built-in web server (free port on `127.0.0.1`, SQLite, cache, logs and share directory in a scratch directory under `$TMPDIR`, or `var/` when unset). It requests `/` until the server answers (30 seconds at most): an answer below 500 is `ready`; a 500, an early exit or no answer is `failed` with the first error line of the server log. It then stops the server, removes the scratch directory, prints the server log to stdout and writes one status line to file descriptor 3. The exit code is 0 exactly when `ready` was written.

For the reviewers to look at closely:
- `fopen('php://fd/3')` duplicates descriptor 3 to a new number, and PHP's `proc_open` hands the child every descriptor it holds, so an explicit spec without index 3 is not enough. The server is started through `sh -c` that closes every descriptor above 2 before `exec`; I checked in `/proc/<pid>/fd` that the server holds none that points at the status file.
- The script needs no Composer autoloader itself (a broken install reports `failed`). It defines `InstanceStatus` and `Instance` in the file, since `composer.json` (protected) has no autoload entry for `bin/`; the status is returned from a `#[NoDiscard]` method.
- When descriptor 3 is not open, no status is written and the exit code is 1.
- Commit 2 removes `.evidence/` files that commit 1 added by mistake (history is not rewritten); they hold only status lines.
- `bin/probe` (part 2) is not touched.

## Evidence

- E1: JSON status of a clean and of a broken boot, without network: below, both run inside a network namespace with only loopback.

Clean and broken boot, no network (`unshare -rn`, loopback up, `ip -brief addr` shows only `lo`):

```
== clean checkout
lo               UNKNOWN        127.0.0.1/8 ::1/128
exit 0
{"status":"ready"}
== broken kernel config (config/packages/broken.yaml: framework: no_such_option: true)
lo               UNKNOWN        127.0.0.1/8 ::1/128
exit 1
{"status":"failed","reason":"HTTP 500: [Tue Oct 27 08:17:01 2026] 2026-10-27T08:17:01+00:00 [critical] Uncaught Exception: Unrecognized option \"no_such_option\" under \"framework\". Available options are \"allowed_http_method_override\", ... "}
```

Acceptance:
- Works offline: the runs above. No process left: `pgrep -x php8.5 | wc -l` printed `0` after the runs; no file left: the `$TMPDIR` used for the runs held no `intakt-instance-*` directory afterwards (test `testNothingIsLeftBehindAfterABoot`).
- Broken config gives `failed` with the error and a non-zero exit: test `testABrokenKernelConfigReportsFailedWithTheErrorAndExitsNonZero` (copy of the project with a broken config).
- Clean boot: `testACleanCheckoutReportsReadyAndExitsZero`. No fd 3: `testWithoutDescriptorThreeNoStatusIsWrittenAndTheExitIsNonZero`. Run time of a boot is about 0.6 s.
- Docs: `docs/architecture/README.md` (section "Instance and probes", now describing `bin/instance` and keeping `bin/probe` planned) and `docs/user/README.md` (new section "Checking that an install boots"). `git grep bin/instance` over `docs/`, `AGENTS.md` and `README.md` found only these statements and the protected-paths list in `AGENTS.md`, which stay true; `docs/plan.md` T31 text stays as the plan.

`vendor/bin/phpunit tests/Application/InstanceTest.php`: `OK (4 tests, 9 assertions)`.

`bin/check --fast`:

```
{"gate":"cs","ok":true,"findings":[],"omitted":0}
{"gate":"phpstan","ok":true,"findings":[],"omitted":0}
{"gate":"rector","ok":true,"findings":[],"omitted":0}
{"gate":"architecture","ok":true,"findings":[],"omitted":0}
{"gate":"comments","ok":true,"findings":[],"omitted":0}
{"gate":"audit","ok":true,"findings":[],"omitted":0}
{"gate":"tests","ok":true,"findings":[],"omitted":0}
{"gate":"secrets","ok":true,"findings":[],"omitted":0}
{"gate":"secrets-history","ok":true,"findings":[],"omitted":0}
{"gate":"commits","ok":true,"findings":[],"omitted":0}
{"gate":"dashes","ok":true,"findings":[],"omitted":0}
{"result":"pass","failed":[]}
```

## Follow-ups

- Booting the kernel (tests, `bin/instance` in the `var/` fallback aside) regenerates the untracked `config/reference.php`, which the `cs` gate then flags; a `.gitignore` entry would stop that. Not done here.
