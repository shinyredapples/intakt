Filed as symfony/symfony#68307 (https://github.com/symfony/symfony/pull/68307) on 2026-11-20 against 8.1. The text below is the pull request as filed, read on 2026-11-22; `fix.patch` holds its three commits (`gh pr diff 68307 --patch`).

Commits: 0db5829fc6 [Messenger][Doctrine] Make the fetched messages a stopped worker did not reach available again; 7279edca7c [Messenger][Doctrine] Skip the worker test when the installed Worker does not close the transports; a6a9522fb8 [Messenger][Doctrine] Make the keepalive leave a message alone once another worker received it

Repository: symfony/symfony, base branch 8.1

Title: [Doctrine][Messenger] Make the fetched messages a stopped worker did not reach available again

| Q             | A
| ------------- | ---
| Branch?       | 8.1
| Bug fix?      | yes
| New feature?  | no
| Deprecations? | no
| Issues        | -
| License       | MIT

With `--fetch-size=N`, the Doctrine transport marks the whole batch as delivered in one `get()`. When the worker stops before it reaches the rest of the batch (`--limit`, `--time-limit`, `--memory-limit`, `--failure-limit`, a signal), those messages keep their `delivered_at`. No worker gets them until `redeliver_timeout` (one hour by default) has passed. Since #67993 the worker stops their keepalive when it stops, so nothing else happens to them.

In 8.2 this also happens without `--fetch-size`: `--fetch-size` defaults to `--concurrency`, so `messenger:consume async --concurrency=4 --limit=10` leaves up to three messages hidden for an hour each time the worker reaches its limit.

Reproduction (SQLite, the options `messenger:consume --fetch-size=2 --limit=1` or, on 8.2, `--concurrency=2 --limit=1` give the worker):

```php
$transport = new DoctrineTransport(new Connection(['redeliver_timeout' => 3600], $dbal), new PhpSerializer());
$transport->send(new Envelope(new Ping(1)));
$transport->send(new Envelope(new Ping(2)));

$dispatcher = new EventDispatcher();
$dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(1));
(new Worker(['async' => $transport], $bus, $dispatcher))->run(['sleep' => 0, 'fetch_size' => 2]);

iterator_to_array($transport->get()); // [] : Ping(2) is hidden for 3600 s
```

Same result on v8.1.11, v8.2.0-BETA2 and `8.2.x-dev` (`d5de0f9fe2`), 2026-11-20, PHP 8.5.12.

The change: the Doctrine `Connection` remembers the `delivered_at` it wrote for each message `get()` returned, until the message is acknowledged or rejected. A worker only touches a row it still holds: `keepalive()` and the release on `close()` both match that remembered `delivered_at`, and `keepalive()` updates the remembered value only when its update matched the row. Once the redeliver timeout has passed and another worker received the message, the row has that worker's later `delivered_at`, so a late keepalive of the first worker changes nothing and its `close()` leaves the message with the other worker. A keepalive for a message this `Connection` did not fetch still updates it as before. `DoctrineReceiver` and `DoctrineTransport` implement `CloseableTransportInterface`; `close()`, which the worker calls once it stopped, sets `delivered_at` back to `NULL` for the messages still remembered. It relies on the Worker closing the transports when it stops (3a38e24303), which is in 8.1 but not released yet; on v8.1.11 itself `close()` is never called.

Without this condition the release would make things worse than today: on 8.1 and 8.2 without this PR, a late keepalive of worker A after worker B took the message over already overwrites B's `delivered_at`, but the message stays hidden. With the release alone, A's `close()` then reset B's delivery and a third worker got the message while B still handled it (SQLite and PostgreSQL 17).

Behavior a user would notice: a message the worker fetched but never finished is available again as soon as the worker stops, instead of after `redeliver_timeout`. This also applies to the message being handled when an exception ends `Worker::run()` (the worker calls `close()` in a `finally`). Before, that message came back after an hour; now a restarted worker gets it at once, so a message that makes the worker fail would come back quickly. Should `close()` keep that message hidden? The receiver cannot tell it apart from the unreached ones today; the worker could pass the messages it did not reach to a new receiver method instead, but that is a new interface for 8.3. Happy to change it either way.

Tests:
- `DoctrineIntegrationTest::testAKeepaliveAfterAnotherWorkerTookTheMessageOverLeavesItWithThatWorker`: a second `Connection` takes the message over after the redeliver timeout, the first one sends a keepalive and releases, a third one gets nothing. It fails without the condition and passes with it on SQLite, PostgreSQL 17, MySQL 8.4 and MariaDB 11, on 8.1, the 8.2 merge-up and v8.2.0-BETA2.
- `testKeepaliveRenewsTheLease` and `testKeepaliveWhileTheConnectionIsInATransaction` set `delivered_at` to 2000-01-01 behind the worker's back, which now reads as another worker's delivery; both fail with the condition, so they now wait for the next second and check that the keepalive wrote a later time.
- `DoctrineIntegrationTest`: a worker stopped by `--limit` in the middle of a batch leaves the rest available (fails on 8.1 without the change), acknowledged and rejected messages are not touched, a message delivered again since the fetch is left alone, a message kept alive is released with its new delivery time.
- `DoctrineReceiverTest`, `DoctrineTransportTest`: `close()` releases.
- Doctrine bridge suite (PHP 8.5.12, PHPUnit 13, `POSTGRES_HOST` PostgreSQL 17 and `PGBOUNCER_HOST` set, nothing skipped): 8.1 150 tests pass, 8.2 merge-up and v8.2.0-BETA2 201 pass. High deps (symfony/messenger 8.2.x-dev, doctrine/dbal 4.7.x-dev): same counts pass. Low deps (`--prefer-lowest --prefer-stable`: symfony/messenger v8.1.0 / v8.2.0-BETA1, doctrine/dbal 4.3.0): all pass, 1 skipped on each branch (on 8.1: the worker test, as v8.1.0 does not close the transports); with PHP 8.5, dbal 4.3.0 also reports 2 PHP deprecations in its PDO PgSQL driver, unrelated to this change.
- `DoctrineIntegrationTest` with `MESSENGER_DOCTRINE_DSN` on PostgreSQL 17, MySQL 8.4 and MariaDB 11, each test on an empty database: the four new tests fail without the change and pass with it, on 8.1 and 8.2. (`testTheTransportIsSetupOnGet` uses SQLite-only SQL and fails on these databases with or without the change.)
- v8.2.0-BETA2 (`b9ebf6b0fb`), 2026-11-20, PHP 8.5.12, PostgreSQL 17, PgBouncer 1.26, MySQL 8.4, MariaDB 11: the 8.2 patch applies unchanged; the reproduction fails on the tag and passes with the change; bridge suite 200 pass before the keepalive condition and 201 with it (with the race test), nothing skipped (194 before the change; without the `Transport/` changes 5 errors, 1 failure); the four new `DoctrineIntegrationTest` tests fail without and pass with the change on all three databases.
- The change merges up to 8.2 with context conflicts only (8.2's keepalive takes the time from `microtime(true)`; the merged code keeps that).
- php-cs-fixer (the repository's config) reports nothing; PHPStan level 5 on the changed classes reports nothing new.
- With `--prefer-lowest` (symfony/messenger v8.1.0, whose Worker does not close the transports yet) the worker test is skipped; the rest of the bridge suite passes there (135 tests, 1 skipped).

Not covered: Redis and Amazon SQS also keep the unreached messages hidden. I ran the reproduction above against both on 8.1 and v8.2.0-BETA2 (fetch size 2, limit 1; Redis 7, and ElasticMQ for SQS). Redis: with a different consumer name per worker, the second message stays in the stopped consumer's pending list until another consumer claims it after `redeliver_timeout` (3600 s by default); a worker with the same consumer name gets it at once. Amazon SQS: `close()` releases the messages left in the buffer, but not the ones `get()` already returned, so the second message stays invisible for the visibility timeout (30 s by default). With a fetch size of 1, neither is affected. Once the approach here is settled, I'll send the same fix for both in follow-up pull requests, unless you'd rather solve it in the Worker for all transports at once.
