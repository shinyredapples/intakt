# symfony/symfony fix pull request (draft, not posted)

Repository: symfony/symfony. Form: fix pull request (CONTRIBUTING / pull request template; bug fixes go to the lowest maintained branch with the bug). Base: `8.1` (`--fetch-size` was added in 8.1 by #63662; 8.2 adds `--concurrency`, whose fetch size defaults to the concurrency, so every concurrent worker hits it). Patch: `fix.patch` (applies to `8.1` at `46cb370c72`; merge-up to `8.2` conflicts in `Connection.php`, `DoctrineTransport.php` and `DoctrineTransportTest.php`, context only).

Commit: `[Messenger][Doctrine] Make the fetched messages a stopped worker did not reach available again`

Title: `[Messenger][Doctrine] Make the fetched messages a stopped worker did not reach available again`

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

The change: the Doctrine `Connection` remembers the delivery time it wrote for each message `get()` returned, until the message is acknowledged or rejected (a keepalive updates it). `DoctrineReceiver` and `DoctrineTransport` implement `CloseableTransportInterface`; `close()`, which the worker calls once it stopped, sets `delivered_at` back to `NULL` for the messages still remembered. The update matches the remembered `delivered_at`, so a message that another worker received after the redeliver timeout stays with that worker.

Behavior a user would notice: a message the worker fetched but never finished is available again as soon as the worker stops, instead of after `redeliver_timeout`. This also applies to the message being handled when an exception ends `Worker::run()` (the worker calls `close()` in a `finally`). Before, that message came back after an hour; now a restarted worker gets it at once, so a message that makes the worker fail would come back quickly. Should `close()` keep that message hidden? The receiver cannot tell it apart from the unreached ones today; the worker could pass the messages it did not reach to a new receiver method instead, but that is a new interface for 8.3. Happy to change it either way.

Tests:
- `DoctrineIntegrationTest`: a worker stopped by `--limit` in the middle of a batch leaves the rest available (fails on 8.1 without the change), acknowledged and rejected messages are not touched, a message delivered again since the fetch is left alone, a message kept alive is released with its new delivery time.
- `DoctrineReceiverTest`, `DoctrineTransportTest`: `close()` releases.
- Doctrine bridge suite on SQLite: 149 tests pass (14 PostgreSQL tests skipped, no PostgreSQL here). php-cs-fixer reports nothing.

Not covered: Redis (pending entries) and Amazon SQS (visibility timeout) also keep a fetched batch from other workers after the worker stops, from reading their code; I did not run them. Should I look at them in follow-up pull requests? The PostgreSQL integration tests were not run.
