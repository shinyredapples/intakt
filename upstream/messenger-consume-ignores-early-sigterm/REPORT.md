# [Messenger] `messenger:consume` ignores a SIGTERM that arrives before its worker exists

Filed as pull request symfony/symfony#67912 (merged into 6.4 on 2026-11-06, released in v8.2.0-BETA2). Affected: 6.4.49, 7.4.22, 8.1.10 and 8.2.0-BETA1; target branch: 6.4. Versions: `symfony/messenger` v8.1.10, `symfony/console` v8.1.10, PHP 8.5.12 with `pcntl`.

## What happens

`Application::doRunCommand()` registers the signal handlers before it dispatches `console.command` and before `ConsumeMessagesCommand::execute()` builds the `Worker`. A SIGTERM in that window reaches `ConsumeMessagesCommand::handleSignal()`, which returns `false` while `$this->worker` is null. `false` means "do not exit", and nothing remembers the signal, so the worker then starts and runs until a limit ends it:

```
SIGTERM before the worker started was ignored: the consumer ran until its time limit (5.0 s)
```

A supervisor that stops a consumer which is still booting (a container stopped right after it started) waits for its own kill timeout, or for `--time-limit`.

## Expected

The signal is not lost: the command remembers it and stops the worker right after the worker is created. Returning an exit code from `handleSignal()` instead is not preferred: it skips `console.terminate` for a worker that never ran.

## Effect in Intakt

`docker/entrypoint.sh` sends SIGTERM to the consumers and kills whatever still runs after a grace period, so a consumer that swallowed its SIGTERM cannot keep the container (or a test) from ending. `tests/Core/Dashboard/Infrastructure/EntrypointProcessesTest.php` proves the kill with a stopped consumer.

## Upstream status

Checked on 2026-11-03, refreshed on 2026-11-06.

- **Pinned versions:** still fails. `reproduce.php` at `symfony/messenger` v8.1.10: `SIGTERM before the worker started was ignored`.
- **Development branch:** not run; `handleSignal()` on the `8.1` and `8.2` branches still starts with `if (!$this->worker) { return false; }` (read on 2026-11-03).
- **Existing issue or pull request:** none covers a signal before the worker exists. Related: #65633 (force quit on repeated SIGINT) and #66950. Also close: #41906 (graceful shutdown under Supervisor), #47809 (commands exiting gracefully).
- **Searches** (`symfony/symfony` issues and pull requests, open and closed): `handleSignal messenger:consume`, `consume SIGTERM worker ignored`.
- **Refresh (2026-11-06):** still reproduces on 6.4.49, 7.4.22, 8.1.10 and 8.2.0-BETA1; not fixed upstream. In the snippet, `addCommand()` is `add()` before 8.x, and on 8.2 the command's dispatcher argument must also implement `ListenerIntrospectionInterface`. Preferred fix: remember the signal and stop the worker right after it is created. Superseded: symfony/symfony#67912 fixed the bug, first released in v8.2.0-BETA2 (see `Decision` and `Status` below).
- **Pull requests and commits:** filed as pull request symfony/symfony#67912 by Intakt (`[Messenger] Stop the worker when a signal arrives before it is created`, merged 2026-11-06 into `6.4` as bb8fbad67f, then merged up into `8.1` and `8.2`), checked 2026-11-15 at `8.2` 979969dc83. The first release that contains it is v8.2.0-BETA2 (2026-11-13); v6.4.50, v7.4.23 and v8.1.11 (all 2026-11-04) do not. Intakt now pins `symfony/messenger` v8.2.0-BETA2, where `reproduce.php` prints `REPRODUCTION PASSES`. Searched pull requests with `is:pr handleSignal consume` (#67912, #66950, #65633) and `is:pr messenger signal before worker`; commits with `git log v8.1.10..origin/8.2` on `Command/ConsumeMessagesCommand.php` and `Worker.php`.
- **Intakt lock (2026-11-16):** passes. `php bin/console intakt:upstream:run` at `symfony/messenger` v8.2.0-BETA2: `passes       messenger-consume-ignores-early-sigterm`.
- **Decision:** `filed as pull request` https://github.com/symfony/symfony/pull/67912, merged into 6.4 on 2026-11-06, released in v8.2.0-BETA2 (recorded 2026-11-20).
- **Status:** `filed as pull request` symfony/symfony#67912, merged into 6.4 on 2026-11-06, released in v8.2.0-BETA2 (recorded 2026-11-20): nothing more to file; `ISSUE.md` was removed. Checked 2026-11-22: still merged and in v8.2.0-BETA2, nothing more to watch.
