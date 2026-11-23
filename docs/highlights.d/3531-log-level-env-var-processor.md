## Symfony components

| DependencyInjection env var processor | `postgresql_server_version` gives a PostgreSQL `DATABASE_URL` a server version and leaves other engines to detect theirs; `log_level` turns `INTAKT_LOG_LEVEL` into the logger's minimum level, `warning` when unset, and refuses an unknown level at boot with the accepted ones | in progress | T3 (#8), `src/Core/Platform/Infrastructure/PostgreSqlServerVersion.php`; #1381 (part #3531), `src/Core/Platform/Infrastructure/LogLevelFromEnvironment.php` |
