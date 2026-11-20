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

`src/Core/Build/UseCase/BuildTask/ParkedTasks.php` begins and commits its transaction itself instead of calling `transactional()`. After a failed parking, `rollBackWhatTheRefusedCommitLeftOpen()` rolls back through DBAL while DBAL still counts a transaction, and otherwise rolls back the native connection when it is still in a transaction. That native rollback runs only on PDO drivers (`getNativeConnection()` is a `PDO`); on any other driver an open transaction left by a refused commit is not rolled back. A failure of the rollback is swallowed, so the caller gets `TaskNotParked`. `tests/Core/Build/UseCase/BuildTask/ParkedTasksOnSqliteTest.php` forces the refused commit with a reader's shared lock.

## Upstream status

- **Pinned versions:** still fails at `doctrine/dbal` 4.6.0 (exit 1, the output above).
- **Development branch:** still fails, run in a scratch project with only DBAL on 2026-11-20: `4.6.x-dev` (6a1c4a5e4ea4) and `5.0.x-dev` (59c7cf020238), both with the same output; `Connection::commit()` and `updateTransactionStateAfterCommit()` are unchanged there.
- **Pull requests and commits:** not searched yet: the build sandbox reaches no issue or pull request search.
- **Existing issue:** not searched yet, for the same reason.
- **Decision:** recheck needed before filing: search doctrine/dbal's issues and pull requests (open, merged and closed) for `commit failed transaction nesting level`, `NoActiveTransaction after commit`, `database is locked commit` and `updateTransactionStateAfterCommit` first.
