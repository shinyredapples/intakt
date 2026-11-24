# Doctor

`php bin/console intakt:doctor` (`Core/Doctor/UseCase/RunDoctor/DoctorCommand`) runs every health check and prints one line each: `pass: <name>` or `FAIL: <name>: <fix>`. It exits 0 only when every check passes. It is read-only: no write, no job start, no model call.

## The contract

`Core/Doctor/Contract/HealthCheck` has `name()` and `run(): CheckResult`; `CheckResult` is passed, or failed with fix text in plain words. The interface is tagged `intakt.doctor.check` (`DoctorServices::CHECK_TAG`) by autoconfiguration, and the command takes the checks with `#[AutowireIterator]`. A module adds a check by implementing the interface in its own `Infrastructure/`.

A check that throws is printed as `The check could not run (<exception class>)` and counts as a failure; the class only, because a message can echo a credential. A check never hangs: the hub reads use the admin API's 2-second timeout, and the GitHub requests use a 5-second timeout and a 15-second total.

## The checks

- `Core/Server/Infrastructure/HubHealthCheck`: the hub reading of `ServerStatus`. `intakt:server:health` calls its `problem()`, so the two doors share one judgement. The admin API answers only inside the container, so the check fails with the refusal message elsewhere.
- `Plugin/GitHubForge/Infrastructure/ForgeCredentialCheck`: one `InstallationReadBack::check()`, the read the start makes. A `ReportableFailure` becomes a failure with fix text; its message holds a status or a class, never a key. With `GITHUB_APP_ID` empty (the demo) it passes without a read.

The checks for the web server answering and the workers running are not built yet.
