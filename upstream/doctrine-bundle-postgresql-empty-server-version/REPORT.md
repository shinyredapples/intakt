# [DoctrineBundle] A PostgreSQL connection without `server_version` fails with `Invalid platform version ""`

Draft, not posted. Versions: `doctrine/doctrine-bundle` 3.3.3, `doctrine/dbal` 4.5.0, PHP 8.5.12.

## What happens

When `charset` is not configured or `dbname_suffix` is set, `ConnectionFactory::createConnection()` picks the platform to choose a default charset, passing `new StaticServerVersionProvider($params['serverVersion'] ?? ... ?? '')`. Without `server_version`, `AbstractPostgreSQLDriver::getDatabasePlatform()` refuses the empty string, so the connection service cannot be built, before any connection to the server is attempted:

```
Doctrine\DBAL\Platforms\Exception\InvalidPlatformVersion: Invalid platform version "" specified. The platform version has to be specified in the format: "<major_version>.<minor_version>.<patch_version>".
```

The MySQL driver accepts the empty string, so the same configuration works on MySQL and MariaDB.

## Expected

The connection is built, and the server version is detected on the first connection, as `Doctrine\DBAL\Connection::getDatabasePlatform()` does when `serverVersion` is not set.

## Effect in Intakt

`config/packages/doctrine.yaml` sets `server_version` through `src/Core/Ledger/Infrastructure/PostgreSqlServerVersion.php`: a PostgreSQL URL gets version 12 (DBAL 4 has no newer PostgreSQL platform), any other URL none, so MySQL, MariaDB and SQLite keep detecting the server.
