Commit: Roll back the transaction when the database refuses to commit it

Repository: doctrine/dbal, base branch 4.6.x, applies to 6a1c4a5e4

Title: Roll back the transaction when the database refuses to commit it

|      Q       |   A
|------------- | -----------
| Type         | bug
| Fixed issues | <!-- none filed -->

#### Summary

Since #6545, `commit()` lowers the nesting level even when the driver's commit fails, on the assumption that a refused commit has ended the transaction. That holds for PostgreSQL and Oracle, which roll it back. SQLite keeps it open when it refuses a `COMMIT` because the database is locked (`LockWaitTimeoutException`). DBAL then reports no active transaction while the driver is still in one:

- `rollBack()` throws `NoActiveTransaction`, so the open transaction can't be ended;
- the next `beginTransaction()` fails with "There is already an active transaction" (pdo_sqlite) or "cannot start a transaction within a transaction" (sqlite3);
- `transactional()` calls `rollBack()` after such a commit, so `NoActiveTransaction` replaces the `LockWaitTimeoutException`.

`commit()` now rolls back the driver's transaction after a refused commit, so the database agrees with the nesting level. A database that has already rolled back makes that rollback fail, and the failure is ignored; the existing deferred-constraint tests on PostgreSQL and Oracle cover that path. No rollback is attempted after `ConnectionLost`.

Since a refused top-level commit now always leaves the transaction ended, `transactional()` no longer rolls back after one. After a refused savepoint release it still rolls back as before, so a caller that catches the exception and commits the enclosing transaction cannot persist the failed inner work. One behavior change follows: work that SQLite refused to commit is rolled back instead of staying in a transaction DBAL no longer tracks. A caller could not retry that commit through DBAL since #6545 either.

Three functional tests cover it: a refused commit through `commit()` and through `transactional()` (SQLite, a second connection holds a read lock on a temporary database file), and a refused savepoint release inside `transactional()` (any platform that releases savepoints).

Open question, happy to change it: should the ignored rollback failure be kept, for example as a suppressed/previous exception, instead of being dropped? When the database refuses both the commit and the rollback, the driver's transaction stays open while DBAL counts none, as it does today. DBAL can't tell in general whether the transaction is still open after a failed rollback, since PostgreSQL and Oracle fail it for a transaction they already ended (#3423).

The bug is on 3.10.x too; I didn't target it since it isn't marked as maintained.
