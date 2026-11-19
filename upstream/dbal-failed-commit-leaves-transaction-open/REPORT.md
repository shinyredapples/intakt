# [DBAL] A COMMIT that SQLite refuses leaves the transaction open while `Connection` counts it closed

Draft, not posted. Versions: `doctrine/dbal` 4.6.0, PHP 8.5.12, SQLite through `pdo_sqlite`, journal mode `DELETE`.

## What happens

`Connection::commit()` lowers the nesting level in a `finally` block, also when the driver's commit throws. On PostgreSQL and MySQL a failed COMMIT ends the transaction, but SQLite keeps it open when the COMMIT is refused because another connection holds a read lock (`SQLITE_BUSY`). `transactional()` then calls `rollBack()`, which finds nesting level 0 and throws `NoActiveTransaction`, replacing the original error. The native transaction stays open: the connection still sees its uncommitted rows, and its next writes join that transaction. `reproduce.php` holds a read transaction on a second PDO connection while `transactional()` inserts a row, and prints:

```
transactional() threw Doctrine\DBAL\Exception\NoActiveTransaction: There is no active transaction.
DBAL transaction active: false, PDO transaction active: true, rows seen: 1
```

## Expected

`transactional()` rethrows the commit's own error (a `LockWaitTimeoutException` for `database is locked`) and leaves no transaction open: either `commit()` keeps the nesting level when the driver reports the transaction still active, or `transactional()` rolls the native transaction back.

## Effect in Intakt

`src/Core/Build/UseCase/BuildTask/ParkedTasks.php` rolls the native PDO transaction back when a parking that opened the outermost transaction fails and PDO still reports it open, so the earlier question stays and the build can record its failure. Only a PDO driver's uncommitted row is discarded this way: on a driver whose native connection is not a `PDO` (for example `sqlite3`), `ParkedTasks` does not roll it back and the row stays in the open transaction. `tests/Core/Build/UseCase/BuildTask/ParkedTasksOnSqliteTest.php` (`testAParkingWhoseCommitIsRefusedByAReaderLeavesNoTransactionOpenAndKeepsTheEarlierQuestion`) forces the order.

## Upstream status

- **Pinned versions:** still fails: `reproduce.php` at `doctrine/dbal` 4.6.0 exits 1 with the output above.
- **Development branch:** `src/Connection.php` on `5.0.x`, read on 2026-11-19, has the same `commit()` (`finally` lowering the level) and the same `transactional()` rollback; the script was not run against it.
- **Pull requests and commits:** not searched yet: the build that found it could not reach GitHub's search.
- **Decision:** recheck needed before filing: run against the default branch and search doctrine/dbal's issues and pull requests (`commit nesting level`, `NoActiveTransaction transactional`, `SQLITE_BUSY commit`).
