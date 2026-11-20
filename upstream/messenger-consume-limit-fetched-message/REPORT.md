# [Messenger] A worker stopped by `--limit` leaves the messages it fetched but did not handle delivered

Draft, not filed. Found by #2723 (part 1 of #1850). Versions: `symfony/messenger` v8.2.0-BETA2, `symfony/doctrine-messenger` v8.2.0-BETA2, `doctrine/dbal` 4.6.0, PHP 8.5.12.

## What happens

`Worker::run()` fetches `fetch_size` envelopes per call to a receiver (`--fetch-size`, which defaults to `--concurrency`, so `messenger:consume --concurrency=2` fetches two). When a listener stops the worker after the first one (`--limit=1`, `StopWorkerOnMessageLimitListener`), the loop breaks and the rest of the fetched batch is neither handled, nor rejected, nor given back: the `finally` block only drops the keepalives of the waiting envelopes ("the rest of the batches is not handled when the worker stops or fails"). The Doctrine transport marked the whole batch delivered in `Connection::get()`, so the unhandled message stays with `delivered_at` set and the next consumer gets it only after `redeliver_timeout` (3600 seconds by default):

```
Handled: 1; rows left: 1, of them waiting (not delivered): 0; the next consumer gets 0 message(s)
The message fetched but not handled at the limit stays delivered, so the next consumer gets it only after redeliver_timeout
```

With `fetch_size` 1 the same run leaves the second message waiting, and the next consumer gets it at once. `--concurrency` is not needed to see it: `--fetch-size=2` alone shows it, `--concurrency` only makes a fetch size above one the default. The same holds for a stop through `--memory-limit`, `--time-limit` or a signal while fetched envelopes are buffered (read in `Worker::run()`, not reproduced here).

`reproduce.php`: two messages on a Doctrine transport over SQLite in memory, a `Worker` with `fetch_size` 2 and `StopWorkerOnMessageLimitListener(1)`, then a check that the second message is still waiting and that a new `get()` returns it.

## Expected

A worker that stops before it handles a fetched envelope gives it back (rejects it as not handled, or resets its delivery so the transport hands it out again at once), or the documentation of `--fetch-size` and of the limits says that fetched envelopes stay delivered until the transport redelivers them.

## Effect in Intakt

`docker/entrypoint.sh` restarts a consumer that ended at its limit. With `--concurrency` and the default fetch size, each such restart would hide a reviewer message for `redeliver_timeout` (90 minutes in Intakt), so a review would stall. `tests/Journeys/ConcurrentConsumerJourneyTest.php` pins the behavior (`replaceAConsumerAtItsMessageLimit()`) and shows that `--fetch-size=1` avoids it; the container keeps several single consumers until this is settled (#2723, decision D3).

## Upstream status

- **Pinned versions:** still fails. `reproduce.php` at `symfony/messenger` v8.2.0-BETA2 exits 1 with the output above; with `fetch_size` 1 it prints `REPRODUCTION PASSES`.
- **Development branch:** not run yet.
- **Pull requests and commits:** not searched yet; this build had no access to upstream's issue and pull request search.
- **Decision:** not decided: the duplicate check (pull requests, the development branch's commits on `Worker.php` and `Transport/Connection.php` of the Doctrine bridge, issues) and a run on the `8.2` branch come first. Upstream may answer that the behavior is meant and only the documentation of `--fetch-size` is missing.
- **Status:** not filed; the owner decides after the duplicate check.
