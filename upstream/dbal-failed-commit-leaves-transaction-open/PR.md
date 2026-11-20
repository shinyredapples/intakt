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

Since a failed driver commit now always leaves the transaction ended, `transactional()` no longer rolls back after one, and the list of exceptions that skipped the rollback is gone. Two behavior changes follow:

- Work that SQLite refused to commit is rolled back instead of staying in a transaction DBAL no longer tracks. A caller could not retry that commit through DBAL since #6545 either.
- When releasing a savepoint fails in a nested `transactional()`, it no longer rolls back the enclosing level; the exception reaches the enclosing `transactional()`, which rolls back its own level.

Open questions, happy to change either:

- Should the ignored rollback failure be kept, for example as a suppressed/previous exception, instead of being dropped?
- Should a failed savepoint release also roll back to that savepoint? I left nested commits as they are; #7545 covers a related case.

The bug is on 3.10.x too; I didn't target it since it isn't marked as maintained.
