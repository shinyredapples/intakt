# Doctor

`php bin/console intakt:doctor` (`Core/Doctor/UseCase/RunDoctor/DoctorCommand`) runs every health check and prints one line each: `pass: <name>` or `FAIL: <name>: <fix>`. It exits 0 only when every check passes. It is read-only: no write, no job start, no model call.

## The contract

`Core/Doctor/Contract/HealthCheck` has `name()` and `run(): CheckResult`; `CheckResult` is passed, or failed with fix text in plain words. The interface is tagged `intakt.doctor.check` (`DoctorServices::CHECK_TAG`) by autoconfiguration, and the command takes the checks with `#[AutowireIterator]`. A module adds a check by implementing the interface in its own `Infrastructure/`.

A check that throws is printed as `The check could not run (<exception class>)` and counts as a failure; the class only, because a message can echo a credential. A check never hangs: the hub reads use the admin API's 2-second timeout, and the GitHub check has one 15-second deadline for all its requests (below).

## The checks

- `Core/Server/Infrastructure/HubHealthCheck`: the hub reading of `ServerStatus`, judged by `Core/Server/Contract/HubVerdict` (`HubState` `Healthy`, `Unhealthy` or `Down`, and `problem()`). The board's `InfrastructureRow` and `intakt:server:health` use the same verdict, so the three never disagree. The fix text says to restart the container from the host and read its log, with the Compose service name (`docker compose restart intakt`, `docker compose logs intakt`), because the shipped `compose.yaml` names the service and lets Compose name the container. The admin API answers only inside the container, so the check fails with the refusal message elsewhere.
- `Plugin/GitHubForge/Infrastructure/ForgeCredentialCheck`: `InstallationReadBack::check()`, the read the start makes, over a chain the check builds for each run:
  - its own `InstallationTokens` and `InstallationTokenMinter`, so no token is shared with the forge;
  - a scratch `RateLimitReadings` (an `ArrayAdapter` and an in-memory lock store), so a reading or a rate-limit pause from its answers never reaches the cache the workers share. It reads the shared store once, with `refuseWhileLimited()`, and fails with the pause's end while the workers' pause lasts, without a request;
  - a `NullLogger`, so the read-back's warnings do not add a trace to the terminal;
  - `DeadlineHttpClient` over the plain `http_client` (no retries): each request gets `timeout` and `max_duration` of 5 seconds or of the time left before the 15-second deadline, whichever is shorter, and a request with no time left is refused with a `TransportException`, so the whole check ends within 15 seconds and a last page that still fits is read.

  A `ReportableFailure` becomes a failure with fix text; its message holds a status or a class, never a key. An `InvalidArgumentException` from the lazily parsed `ExpectedInstallation` becomes a fixed failure that names `GITHUB_APP_PERMISSIONS` and `GITHUB_APP_REPOSITORIES` and leaves the exception's message out, because it can quote a setting's text with its line breaks. With `GITHUB_APP_ID`, `GITHUB_APP_INSTALLATION_ID` and `GITHUB_APP_PRIVATE_KEY` all empty (the demo) it passes without a read; with only `GITHUB_APP_ID` empty it fails and names the setting.

The checks for the web server answering and the workers running are not built yet.
