Filed as symfony/symfony#68358 (https://github.com/symfony/symfony/pull/68358), posted on 2026-11-23 against the `8.2` base branch with milestone 8.3, following symfony/symfony#68307 (closed on 2026-11-23). The text below is the pull request as posted, read on GitHub on 2026-11-24; `fix.patch` holds its one commit (the patch of the pull request's head commit, read the same day).

Commits: 2c984d3084 [Messenger] Release the fetched messages a stopped worker did not start handling

Repository: symfony/symfony, base branch 8.2

Title: [Messenger] Release the fetched messages a stopped worker did not start handling

| Q             | A
| ------------- | ---
| Branch?       | 8.3
| Bug fix?      | no
| New feature?  | yes
| Deprecations? | no
| Issues        | -
| License       | MIT

Follows #68307.

With `--fetch-size=N` the Doctrine transport marks a whole batch as delivered in one `get()`. When the worker stops before it starts the rest (`--limit`, `--memory-limit`, `--failure-limit`, a signal, an exception), those messages stay hidden until `redeliver_timeout` (one hour by default).

Only the worker knows which fetched messages it did not start (since #67993 it reads the whole batch first), so this adds an opt-in interface:

```php
interface ReleasableReceiverInterface extends ReceiverInterface
{
    public function release(Envelope $envelope): void;
}
```

- `Worker`: when `run()` ends, it calls `release()` for each fetched message it did not start, before closing the receivers. Not for the message being handled, one skipped with `shouldHandle(false)`, or one a batch handler holds, so a message that makes the worker fail still waits for `redeliver_timeout`. A failing `release()` is logged and the receivers are still closed.
- Doctrine: `DoctrineReceiver` and `DoctrineTransport` implement it. `release()` clears `delivered_at` only where the row still has the value this connection wrote, so another worker's claim is never undone. `keepalive()` gets the same guard; today a late keepalive can overwrite another worker's `delivered_at`. On PostgreSQL a release sends `NOTIFY`, like a send does.
- `close()`, `CloseableTransportInterface` and the other transports are unchanged. The bridge requires `symfony/messenger: ^8.3`.

Tests: 34 new `WorkerTest` cases (every way `run()` ends, and the cases where nothing must be released) and 18 bridge tests on `Connection` and `PostgreSqlConnection` (release, concurrent workers, a late keepalive, the `LISTEN` wake-up, a real `Worker` stopping mid-batch). The Messenger and Doctrine bridge suites pass on SQLite and PostgreSQL 17, on PHP 8.4 and 8.5. On MySQL 8.4 the new tests pass one at a time; the full bridge suite fails there before and after this change, because its integration tests share one table without cleanup.

Open points:
- `Connection` keeps one entry per fetched message until ack, reject or release, so a message skipped with `shouldHandle(false)` keeps its entry until the worker ends. Should `get()` drop entries older than `redeliver_timeout`?
- After a failed `release()` the worker stops releasing for that transport instead of trying each message. Should it try each one?
