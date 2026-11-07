# [Messenger] `messenger:consume` ignores a SIGTERM that arrives before its worker exists

Draft, not posted. Affected: 6.4.49, 7.4.22, 8.1.10 and 8.2.0-BETA1; target branch: 6.4. Versions: `symfony/messenger` v8.1.10, `symfony/console` v8.1.10, PHP 8.5.12 with `pcntl`.

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
- **Refresh (2026-11-06):** still reproduces on 6.4.49, 7.4.22, 8.1.10 and 8.2.0-BETA1; not fixed upstream. In the snippet, `addCommand()` is `add()` before 8.x, and on 8.2 the command's dispatcher argument must also implement `ListenerIntrospectionInterface`. Preferred fix: remember the signal and stop the worker right after it is created.
- **Decision:** `ready to file`; the issue text is `ISSUE.md`.
- **Status:** `recheck needed before filing`: the report has no "Checked" line, so the owner redoes the dated check against upstream first (see `upstream/README.md`). The issue text is `ISSUE.md`.
