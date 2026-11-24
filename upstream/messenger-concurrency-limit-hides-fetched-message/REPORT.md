# [Messenger][Doctrine] A worker that stops in the middle of a batch fetched with `--fetch-size` hides the rest of it until `redeliver_timeout`

Filed as pull request [symfony/symfony#68358](https://github.com/symfony/symfony/pull/68358) on 2026-11-23, a feature for 8.3 that replaces [symfony/symfony#68307](https://github.com/symfony/symfony/pull/68307) (filed against 8.1 on 2026-11-20, closed without merging on 2026-11-23; see `## Upstream status`). Found by the `--concurrency` proof of #2723 (#3020). Versions: `symfony/messenger` and `symfony/doctrine-messenger` v8.2.0-BETA2 (Intakt's), `doctrine/dbal` 4.6.0, SQLite through `pdo_sqlite`, PHP 8.5.12.

## What happens

With `--fetch-size=N`, the Doctrine transport's `Connection::get()` sets `delivered_at` on the whole batch in one statement. The worker hands out the first message, reaches its `--limit` and stops. The second message keeps its `delivered_at`, so no worker gets it until `redeliver_timeout` has passed (one hour by default, 5400 seconds in Intakt). Since symfony/symfony#67993 the worker also stops its keepalive when it stops. In 8.2, `--fetch-size` defaults to `--concurrency`, so a concurrent worker fetches a batch the same way; the parallel execution strategy that `--concurrency` installs was not run here.

`reproduce.php` drives the serial `Worker` with `fetch_size` 2 and `StopWorkerOnMessageLimitListener(1)` on an in-memory SQLite database, then asks the transport for the next message as a restarted worker would. It prints:

```
The stopped worker handled: 1
The next worker got nothing: message 2 stays delivered and hidden until redeliver_timeout (3600 s).
REPRODUCTION FAILS
```

## Expected

A message the worker fetched but never reached is available again once the worker stopped. `--fetch-size` is documented as a performance option (fewer round trips), not as an option that delays messages; neither the command help nor the documentation mentions it, and symfony/symfony#63662, which added `--fetch-size`, does not discuss it.

## Effect in Intakt

The image runs `messenger:consume async` with `--memory-limit` and `--time-limit`, without `--limit` and without `--fetch-size`. The journey of #2723 starts `--concurrency` consumers, whose fetch size defaults to the concurrency. Nothing in Intakt changes for this report.

## Upstream status

- **Checked:** 2026-11-22: `reproduce.php` (`php -d auto_prepend_file=../../vendor/autoload.php reproduce.php` from its folder, run as a regular user) at Intakt's pinned versions, `symfony/messenger` v8.2.0-BETA2 (8f554b6cef), `symfony/doctrine-messenger` v8.2.0-BETA2 (12a91336d4), `doctrine/dbal` 4.6.0, PHP 8.5.12: the output above, exit code 1. `php bin/console intakt:upstream:run` on a folder holding a copy of this one: `still fails  messenger-concurrency-limit-hides-fetched-message`. The run of 2026-11-20 found the same on v8.1.11 and on `8.2.x-dev` (`symfony/messenger` d5de0f9fe2); those were not rerun on 2026-11-22.
- **Related:** symfony/symfony#63662 (added `--fetch-size` in 8.1), #63650 (added `--concurrency` in 8.2), #67993 (keepalive for the waiting messages of a batch; it stops their keepalive when the worker stops), #64128 and #67556 (`--fetch-size` validation).
- **Pull requests and commits:** none found that fix it (checked 2026-11-20, symfony/symfony issues and pull requests, open and closed: `concurrency limit messenger`, `fetch-size`, `fetch_size messenger`, `concurrency redeliver`, `redeliver_timeout worker stop`, `fetched messages messenger stop`, `keepalive fetched`, `unacked worker stop messenger`, `messenger concurrency doctrine`, `messenger parallel limit`). On 2026-11-22 the commits on `src/Symfony/Component/Messenger/Bridge/Doctrine/Transport/Connection.php` since 2026-11-01 on `8.1` (head 46cb370c72) and `8.2` (head 2894e4eeea) are b4a31c8508, fe6effb6b7, 29ee3699db and cfdd11f089 and their merges (integer options, message ids); none of them releases a fetched message.
- **Form and branch:** first a fix pull request against `8.1`, the lowest maintained branch with the bug (`--fetch-size` is new in 8.1), symfony/symfony#68307. Source: the symfony/symfony pull request template and contributing guide (bug fixes go to the lowest maintained branch and are merged up). Since 2026-11-23 a feature pull request for 8.3, symfony/symfony#68358 (base branch `8.2`, milestone 8.3).
- **First pull request (symfony/symfony#68307, closed):** as read on 2026-11-22 it had three commits. Its third commit makes `keepalive()` and the release on `close()` touch only a row whose `delivered_at` the worker still holds, so a late keepalive after another worker took the message over leaves that delivery alone, one of the two constraints #3020 names. The other one, that a message whose handling ended `Worker::run()` with an exception must not come back early, was an open question in its text: with that change `close()` released that message too. It did not mention a hard kill (no `close()` call). It named Redis and Amazon SQS as keeping unreached messages hidden as well, left to follow-up pull requests.
- **State on 2026-11-22:** open, labels `Bug`, `Doctrine`, `Messenger` and `Status: Needs Review`, milestone 8.1, no review and no comment, all 14 checks green (read with `gh pr view 68307 -R symfony/symfony`).
- **State on 2026-11-23:** open, with a maintainer review on 2026-11-23: release only the messages the worker did not get yet; the message being handled when an exception ends `run()` stays hidden until `redeliver_timeout` (back-off), which settles the open question above. On that day a follow-up commit was planned (recorded by #3659); the state of 2026-11-24 below replaced it.
- **State on 2026-11-24:** read on GitHub (REST API) on 2026-11-24 (#3722). symfony/symfony#68307 is closed without merging since 2026-11-23. symfony/symfony#68358, "[Messenger] Release the fetched messages a stopped worker did not start handling", was opened on 2026-11-23 and is open: labels `Feature`, `Messenger` and `Status: Needs Review`, milestone 8.3, base branch `8.2`, one commit, no review and no comment, 9 of 14 checks green and 5 failing (four `Unit Tests` jobs and `x86 / minimal-exts / lowest-php`). It says it follows #68307 and adds an opt-in `ReleasableReceiverInterface::release(Envelope)` that `Worker` calls for each fetched message it did not start, implemented by the Doctrine transport. `PR.md` is its text and `fix.patch` its commit, both as read on 2026-11-24. `reproduce.php` was not rerun.
- **Decision:** `filed as pull request` [symfony/symfony#68358](https://github.com/symfony/symfony/pull/68358) (2026-11-23), replacing [symfony/symfony#68307](https://github.com/symfony/symfony/pull/68307) (filed against 8.1 on 2026-11-20 by the owner, as recorded on #3020 on 2026-11-21, closed on 2026-11-23); next: watch the review and CI of #68358.
