# [DBAL] A commit the database refuses leaves the driver's transaction open while DBAL counts it as ended

Draft, not posted. Versions: `doctrine/dbal` 4.6.0, PHP 8.5.12, SQLite through `pdo_sqlite`.

## What happens

`Connection::commit()` calls the driver's `commit()` and, in a `finally`, `updateTransactionStateAfterCommit()`, which lowers the nesting level whether the commit succeeded or not. When SQLite refuses the `COMMIT` (`database is locked`, converted to `LockWaitTimeoutException`, here because another connection holds a read transaction), SQLite keeps the transaction open and PDO still reports it, but `isTransactionActive()` answers false and `rollBack()` throws `NoActiveTransaction`. `transactional()` calls `rollBack()` in a `finally` after such a commit, so its `NoActiveTransaction` replaces the `LockWaitTimeoutException`, and the connection stays inside the open transaction: the next `beginTransaction()` fails with PDO's "There is already an active transaction". `reproduce.php` prints:

```
COMMIT refused: Doctrine\DBAL\Exception\LockWaitTimeoutException
DBAL transaction active: false, driver transaction active: true
```

## Expected

After a refused commit, DBAL and the driver agree: either DBAL rolls the driver's transaction back, or it keeps counting the transaction so `rollBack()` ends it.

## Effect in Intakt

`src/Core/Build/UseCase/BuildTask/ParkedTasks.php` begins and commits its transaction itself instead of calling `transactional()`. After a failed parking, `rollBackWhatTheRefusedCommitLeftOpen()` rolls back the native connection when it is still in a transaction, then closes the DBAL connection to reset its count of open transactions. That native rollback runs only on PDO drivers (`getNativeConnection()` is a `PDO`); on any other driver an open transaction left by a refused commit is not rolled back by Intakt and ends only with the connection the close drops. A failure of the rollback is swallowed, so the caller gets `TaskNotParked`. `tests/Core/Build/UseCase/BuildTask/ParkedTasksOnSqliteTest.php` forces the refused commit with a reader's shared lock.

## Upstream status

- **Checked:** 2026-11-20: `reproduce.php` ran against the newest release 4.6.0 and the default branch 4.6.x at 6a1c4a5e4 (2026-11-19), PHP 8.5.12, SQLite 3.46.1 through `pdo_sqlite`; both print the output above and exit 1. Searched doctrine/dbal's open, merged and closed pull requests and open and closed issues for `commit failed transaction nesting level`, `NoActiveTransaction after commit`, `database is locked commit`, `updateTransactionStateAfterCommit`, `commit fails transaction still active`, `There is already an active transaction`, `failed commit`, `commit failure`, `commit throws`, `database is locked`, `LockWaitTimeoutException`, `TransactionRolledBack` and `sqlite busy commit`, and the 4.6.x, 4.7.x and 5.0.x commits on `src/Connection.php` since 4.6.0 (none change `commit()` or `transactional()`).

- **Pinned versions:** still fails at `doctrine/dbal` 4.6.0 (exit 1, the output above).
- **Development branch:** still fails on 4.6.x (6a1c4a5e4). `commit()` and `transactional()` are the same on 4.7.x (f534431e5) and 5.0.x (59c7cf020).
- **Origin:** a regression of doctrine/dbal#6545 (commit 6061e271b, first in 3.9.4 and 4.2.2), the fix for doctrine/dbal#3423. Since then `commit()` lowers the nesting level in a `finally` on the assumption that a refused commit has ended the transaction, which holds for PostgreSQL and Oracle (they roll it back) but not for SQLite's `database is locked`. Before 3.9.4 the level stayed and `rollBack()` worked.
- **Existing issue:** none found for this case. doctrine/dbal#3423 (closed 2025-01-16) is the opposite case, a database that already rolled back, and its fix caused this one. doctrine/dbal#7549 (closed 2026-11-04, fixed by #7550) is the OCI8 driver staying in non-autocommit mode after a refused commit: driver state, not the wrapper's count. doctrine/dbal#6049 (closed) reported "There is no active transaction" after a failed commit without a reproduction, before #6545. doctrine/dbal#7545 (open) is a deadlock inside a nested transaction (savepoint release), a different path.
- **Pull requests and commits:** none fixes it (checked 2026-11-20). Related: doctrine/dbal#6545 (merged, introduced it), #4846 and #3424 (closed, earlier attempts at #3423), #7550 (merged, OCI8 driver only), #7611 (closed, nested rollback for #7545).
- **Form and target:** a fix pull request against 4.6.x: doctrine/dbal's `AGENTS.md` sends a bug fix to the branch marked `current` in `.doctrine-project.json` (4.6.x); maintainers merge up. 3.10.x has the bug too but is not marked maintained. #6545 and #7550 were fix pull requests without a separate issue.
- **Fix pull request:** `fix pull request ready` in this folder: `fix.patch` (applies to 4.6.x 6a1c4a5e4, also to 4.7.x; 5.0.x needs the import list merged) and `PR.md`. `commit()` rolls back the driver's transaction after a refused commit, ignoring a failing rollback and skipping it after `ConnectionLost`; `transactional()` no longer rolls back after a failed commit. Two new functional tests in `tests/Functional/TransactionTest.php` fail without the change on `pdo_sqlite` and `sqlite3` and pass with it; the full `pdo_sqlite` and `sqlite3` suites, PHPStan and PHP_CodeSniffer pass (one failure on both, `ExceptionTest::testConnectionExceptionSqLite`, also fails without the change when run as root). PostgreSQL and Oracle paths were not run locally; upstream CI covers them through the deferred-constraint tests.
- **Decision:** `filed as pull request` [doctrine/dbal#7633](https://github.com/doctrine/dbal/pull/7633) against 4.6.x on 2026-11-20 (the owner's OK on `PR.md`); next: watch its review and CI.
