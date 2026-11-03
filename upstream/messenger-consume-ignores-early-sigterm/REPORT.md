# [Messenger] `messenger:consume` ignores a SIGTERM that arrives before its worker exists

Draft, not posted. Versions: `symfony/messenger` v8.1.10, `symfony/console` v8.1.10, PHP 8.5.12 with `pcntl`.

## What happens

`Application::doRunCommand()` registers the signal handlers before it dispatches `console.command` and before `ConsumeMessagesCommand::execute()` builds the `Worker`. A SIGTERM in that window reaches `ConsumeMessagesCommand::handleSignal()`, which returns `false` while `$this->worker` is null. `false` means "do not exit", and nothing remembers the signal, so the worker then starts and runs until a limit ends it:

```
SIGTERM before the worker started was ignored: the consumer ran until its time limit (5.0 s)
```

A supervisor that stops a consumer which is still booting (a container stopped right after it started) waits for its own kill timeout, or for `--time-limit`.

## Expected

The signal is not lost: either the command exits at once (it has not received a message yet), or it remembers the signal and stops the worker as soon as the worker exists.

## Effect in Intakt

`docker/entrypoint.sh` sends SIGTERM to the consumers and kills whatever still runs after a grace period, so a consumer that swallowed its SIGTERM cannot keep the container (or a test) from ending. `tests/Core/Dashboard/Infrastructure/EntrypointProcessesTest.php` proves the kill with a stopped consumer.

## Upstream status

Checked on 2026-11-03.

- **Pinned versions:** still fails. `reproduce.php` at `symfony/messenger` v8.1.10: `SIGTERM before the worker started was ignored`.
- **Development branch:** not run; `handleSignal()` on the `8.1` and `8.2` branches still starts with `if (!$this->worker) { return false; }` (read on 2026-11-03).
- **Existing issue or pull request:** none found. Closest: #65633 (force quit on repeated SIGINT), #41906 (graceful shutdown under Supervisor), #47809 (commands exiting gracefully); none covers a signal before the worker exists.
- **Searches** (`symfony/symfony` issues and pull requests, open and closed): `handleSignal messenger:consume`, `consume SIGTERM worker ignored`.
- **Decision:** `ready to file`; the issue text is `ISSUE.md`.
