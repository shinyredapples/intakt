## Doctor

#3865, part 2 of #3826. `php bin/console intakt:doctor` (`src/Core/Server/UseCase/Doctor/DoctorCommand`) runs the infrastructure health checks from the terminal, so an owner can see what is wrong with an install even when the dashboard does not start. In the shipped compose stack: `docker compose exec intakt php bin/console intakt:doctor`.

- Same checks, same code (decision D1): it reads `Contract/ServerStatus::read()` once and judges the reading with `Contract/InfrastructureChecks::of()`, whose hub check is the shared `Contract/HubVerdict`. It has no judgement of its own; the checks and their fix texts are described in [Server status](server-status.md).
- Read-only (D3): the reading is three `GET` requests to FrankenPHP's admin API (`/frankenphp/threads`, `/metrics`, `/mercure/health/ready`). It writes nothing, dispatches no message, starts nothing and calls no model.
- The checks, in this order, each with its display name: `worker threads` (`InfrastructureChecks->threads`), `server metrics` (`->metrics`) and `Mercure hub` (`->hub`).
- Output (D4): one line per check, `pass: <name>`, or `fail: <name>: <problem>; fix: <fix>`. Every line goes through one helper that turns each control character (`\p{Cc}`, so line breaks, tabs, escape and C1 codes) and each Unicode line or paragraph separator into a space, after `mb_scrub()` replaces bytes that are not UTF-8 with `?`. Lines are written raw, so a reason that looks like console markup is printed as it is.
- Exit code: 0 only when every check passes, otherwise 1.
- A piece that is down (D5): each admin API call has the 2-second timeout and limit of `Infrastructure/AdminApi`, so a stopped or hanging web server makes each check fail with `<source> down (<path> did not answer)` within a few seconds instead of hanging. A `FRANKENPHP_ADMIN_URL` that `AdminApi` refuses (not plain `http` on `localhost`, `127.0.0.1` or `[::1]`) sends nothing; every check then fails as `cannot run (<message>)` with the fix `set FRANKENPHP_ADMIN_URL to plain http on localhost, for example http://localhost:2019`.

A stopped web server prints:

```
fail: worker threads: worker threads down (/frankenphp/threads did not answer); fix: check that FrankenPHP is running and that FRANKENPHP_ADMIN_URL points at its admin API
fail: server metrics: server metrics down (/metrics did not answer); fix: check that the Caddyfile's global options turn on metrics and that FRANKENPHP_ADMIN_URL points at the admin API
fail: Mercure hub: hub down (/mercure/health/ready did not answer); fix: check that FrankenPHP runs with the Caddyfile's mercure block and that FRANKENPHP_ADMIN_URL points at its admin API
```

Tests: `tests/Core/Server/DoctorCommandTest` replays the recorded admin API answers (`RecordedAdminApi`): a healthy install, each check failing with its fix text, a stopped web server, a refused URL, a reason with line breaks and escape codes, bytes that are not UTF-8, and a reason that looks like console markup.
