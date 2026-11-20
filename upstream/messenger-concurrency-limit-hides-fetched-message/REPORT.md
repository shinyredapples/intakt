# [Messenger][Doctrine] A worker stopped by `--limit` hides the rest of its fetched batch until `redeliver_timeout`

Draft, not posted. Found by the `--concurrency` proof of #2723 (#3020). Affected: v8.1.11 (with `--fetch-size`), v8.2.0-BETA2 and `8.2.x-dev` (also with `--concurrency` alone); target branch: 8.1. Versions: `symfony/messenger` and `symfony/doctrine-messenger` v8.2.0-BETA2 (Intakt's), `doctrine/dbal` 4, SQLite, PHP 8.5.12.

## What happens

With `--fetch-size=N`, the Doctrine transport's `Connection::get()` sets `delivered_at` on the whole batch in one statement. In 8.2, `--fetch-size` defaults to `--concurrency`, so `messenger:consume async --concurrency=2 --limit=1` fetches two messages. The worker hands out the first one, reaches its limit and stops. The second message keeps its `delivered_at`, so no worker gets it until `redeliver_timeout` has passed (one hour by default, 5400 seconds in Intakt). Since symfony/symfony#67993 the worker also stops its keepalive when it stops. The same happens on any stop in the middle of a batch: `--time-limit`, `--memory-limit`, `--failure-limit`, a signal.

```
The stopped worker handled: 1
The next worker got nothing: message 2 stays delivered and hidden until redeliver_timeout (3600 s).
REPRODUCTION FAILS
```

`reproduce.php` runs the `Worker` with the options `--concurrency=2 --limit=1` gives it (`fetch_size` 2, `StopWorkerOnMessageLimitListener(1)`) on an in-memory SQLite database, then asks the transport for the next message as a restarted worker would.

## Expected

A message the worker fetched but never reached is available again once the worker stopped. `--fetch-size` and `--concurrency` are documented as performance options (fewer round trips, parallel handling), not as options that delay messages; neither the command help nor the documentation mentions it, and symfony/symfony#63662, which added `--fetch-size`, does not discuss it.

## Effect in Intakt

The image runs `messenger:consume async` with `--memory-limit` and `--time-limit`, without `--limit`. The journey of #2723 starts `--concurrency` consumers; when one stops in the middle of a batch, the rest of the batch waits 5400 seconds (`config/packages/messenger.yaml`) before another worker reviews it. Nothing in Intakt changes for this report.

## Upstream status

- **Checked:** 2026-11-20. `reproduce.php` still fails on the newest releases v8.2.0-BETA2 (`symfony/messenger` 8f554b6cef, `symfony/doctrine-messenger` 12a91336d4) and v8.1.11 (`symfony/messenger` 9e155bedfc, `symfony/doctrine-messenger` 74e6045c97), and on the default branch `8.2` (`8.2.x-dev`, `symfony/messenger` d5de0f9fe2, `symfony/symfony` 2894e4eeea), run in a scratch copy, never through Intakt's `composer.json`. Duplicate search on symfony/symfony issues and pull requests, open and closed (API search, 2026-11-20): `concurrency limit messenger`, `fetch-size`, `fetch_size messenger`, `concurrency redeliver`, `redeliver_timeout worker stop`, `fetched messages messenger stop`, `keepalive fetched`, `unacked worker stop messenger`, `messenger concurrency doctrine`, `messenger parallel limit`, `messages waiting turn worker`, `invisible redeliver messenger`: none reports it.
- **Related:** #63662 (added `--fetch-size` in 8.1), #63650 (added `--concurrency` in 8.2), #67993 (keepalive for the waiting messages of a batch; it stops their keepalive when the worker stops, leaving them to the redeliver timeout, and names the SQS buffer as a separate case), #64128 and #67556 (`--fetch-size` validation).
- **Form and branch:** a fix pull request against `8.1`, the lowest maintained branch with the bug (`--fetch-size` is new in 8.1; 7.4 fetches one message, so it is not affected). Source: the symfony/symfony pull request template and contributing guide (bug fixes to the lowest maintained branch, merged up by the maintainers).
- **Fix:** `fix.patch` on `8.1` at 46cb370c72 (2026-11-19). The Doctrine `Connection` remembers the delivery time of each fetched message until it is acknowledged or rejected; `DoctrineReceiver` and `DoctrineTransport` implement `CloseableTransportInterface`, and `close()`, which the worker calls once it stopped, sets `delivered_at` back to `NULL` for those messages, unless another delivery changed it since. The new `DoctrineIntegrationTest` worker test fails without the change; the Doctrine bridge suite passes (149 tests, 14 PostgreSQL tests skipped), php-cs-fixer clean, and `reproduce.php` passes against the patched `8.1`. The merge-up to `8.2` conflicts in context only and was not run.
- **Decision:** `ready to file` as a fix pull request; the text is `PR.md`, which names the behavior change (the message being handled when an exception ends the worker comes back at once) and offers Redis and SQS as follow-ups.
- **Fix pull request:** `fix pull request ready` (`fix.patch`, `PR.md`); the owner signs the commit and opens it.
