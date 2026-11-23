# [DoctrineBundle] A PostgreSQL connection without `server_version` fails with `Invalid platform version ""`

Draft, not posted. Versions: `doctrine/doctrine-bundle` 3.3.3, `doctrine/dbal` 4.5.0, PHP 8.5.12.

## What happens

When `charset` is not configured, `ConnectionFactory::createConnection()` picks the platform to choose a default charset, passing `new StaticServerVersionProvider($params['serverVersion'] ?? ... ?? '')`. Without `server_version`, `AbstractPostgreSQLDriver::getDatabasePlatform()` refuses the empty string (it throws on an unparsable version since DBAL 4.1, doctrine/dbal#6495), so the connection service cannot be built, before any connection to the server is attempted. `reproduce.php` builds a connection from a PostgreSQL URL alone and prints:

```
Doctrine\DBAL\Platforms\Exception\InvalidPlatformVersion: Invalid platform version "" specified. The platform version has to be specified in the format: "<major_version>.<minor_version>.<patch_version>".
```

The MySQL driver accepts the empty string, so the same configuration works on MySQL and MariaDB.

## Expected

The connection is built, and the server version is detected on the first connection, as `Doctrine\DBAL\Connection::getDatabasePlatform()` does when `serverVersion` is not set.

## Effect in Intakt

`config/packages/doctrine.yaml` sets `server_version` through `src/Core/Platform/Infrastructure/PostgreSqlServerVersion.php`: a PostgreSQL URL gets version 12 (DBAL 4 has no newer PostgreSQL platform), any other URL none, so MySQL, MariaDB and SQLite keep detecting the server.

## Upstream status

Checked on 2026-11-02, refreshed on 2026-11-06 (DoctrineBundle 3.3.4, 3.4.x and 4.0.x; DBAL 4.5, 4.6 and 5.0).

- **Pinned versions:** still fails. `php bin/console intakt:upstream:run` at `doctrine/doctrine-bundle` 3.3.3 and `doctrine/dbal` 4.5.0: `still fails  doctrine-bundle-postgresql-empty-server-version`.
- **Development branch:** still fails on every branch that installs. Neither repository has a `main` branch: their default branches are 3.3.x (bundle) and 4.5.x (DBAL). The same run in a scratch copy with the bundle at `3.3.x-dev` (7b914210c91f) and DBAL at `4.5.x-dev` (93469c115cb1): `still fails  doctrine-bundle-postgresql-empty-server-version`. The newer branches conflict with Intakt's ORM and migrations bundle, so `reproduce.php` ran with the runner's command line in a scratch project holding only the bundle and DBAL: bundle `3.4.x-dev` (5d12c353e103) with DBAL `4.6.x-dev` (a5dc1fa93c3d), and bundle `4.0.x-dev` (cc3f9d740f3c) with DBAL `4.6.x-dev`, each exit 1 with the same message. DBAL `5.0.x-dev` installs with no bundle branch (4.0.x requires `doctrine/dbal` ^4.0).
- **Existing issue or pull request:** none found for the bundle. Same bug: doctrine/dbal#6530 (reported from an application: the same message for a PostgreSQL URL without `serverVersion`; closed as not planned and locked). Candidates, none the same bug: doctrine/DoctrineBundle#2325 (merged on 2026-10-29, released in 3.3.4, resolves the platform only when no `charset` is configured; the same code path, but this reproduction sets no `charset`, so it still fails), doctrine/DoctrineBundle#2170, #2251, #2252, #2261 and #2263 (`doctrine:database:create` without `server_version`; #2252 was reverted by #2263).
- **Searches** (issues and pull requests, open and closed): `doctrine/DoctrineBundle`: `server_version postgresql`, `server_version`, `"Invalid platform version"`, `StaticServerVersionProvider`, `dbname_suffix serverVersion`, `ConnectionFactory charset platform`, `getDatabasePlatform serverVersion`; `doctrine/dbal`: `"Invalid platform version"`.
- **Earlier decision:** `fix pull request ready` against doctrine/DoctrineBundle (2.19.x), held for the owner; it names #2325 and doctrine/dbal#6530.
- **Owner review (2026-11-03, DoctrineBundle 3.3.x):** a missing `charset` alone triggers the bug, so `reproduce.php` no longer sets `dbname_suffix` (it still fails at the pinned versions). The trigger is DBAL 4.1 (doctrine/dbal#6495) making `AbstractPostgreSQLDriver::getDatabasePlatform()` throw on an unparsable version. #2325 merged on 2026-10-29 and is released in DoctrineBundle 3.3.4, so `charset: utf8` avoids the bug there. doctrine/dbal#6530 is the same bug, reported from an application, closed as not planned and locked; it was never filed on the bundle. A configured `charset` avoids the bug; leaving out both `charset` and `server_version` fails on every maintained branch; DBAL 5 no longer checks the version. The prepared fix pull request targets 2.19.x and stays with the owner; there is no issue text in this folder. Status then: `fix pull request ready` against doctrine/DoctrineBundle (2.19.x).
- **Status:** `filed as pull request` doctrine/DoctrineBundle#2335 ("Build PostgreSQL connections without a server version", base 2.19.x), merged. It was merged into 2.19.x on 2026-11-13 by a maintainer (merge commit 4805025). During review the maintainer added a test that a configured invalid server version still throws, and a test that MySQL without a server version keeps `utf8mb4` was added after the maintainer asked whether the fallback could swallow a MySQL error. Still pending: a release, and the maintainers' merge-ups from 2.19.x into the 3.x branches Intakt installs. Until a release Intakt installs contains the fix, `PostgreSqlServerVersion` keeps setting `server_version` (see "Effect in Intakt"); removing it is a separate issue then. Checked 2026-11-16: no release contains it yet; 2.19.4 (2026-11-12) is older than the merge, and 3.4.0 and 3.4.2 (2026-11-15) do not contain the merge commit (GitHub compare: diverged). Next step: a release that contains it, then removing Intakt's `PostgreSqlServerVersion` workaround as a separate issue. Checked 2026-11-22: no newer DoctrineBundle release than 3.4.2 (2026-11-15), so no release contains it yet.
