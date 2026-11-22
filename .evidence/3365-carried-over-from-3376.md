# Evidence and maintainer decisions carried over from #3376

The `## Evidence` and `## Maintainer decisions (2026-11-22)` sections of the closed pull request #3376, copied word for word from its published description (read on 2026-11-22). The content they describe arrived on this branch with the applied diff (commit aced8c842); this build's own evidence is in the pull-request description.

## Evidence

Live state read on 2026-11-22, for every upstream thread Intakt sent:

| Thread | Kind | State | Date | Release |
|---|---|---|---|---|
| symfony/ai#2874 | pull request | merged | 2026-11-03 | none after v1.0.0-BETA1 |
| symfony/ai#2875 | pull request | merged | 2026-11-06 | none after v1.0.0-BETA1 |
| symfony/ai#2876 | pull request | merged | 2026-11-03 | none after v1.0.0-BETA1 |
| symfony/ai#2888 | pull request | merged | 2026-11-06 | none after v1.0.0-BETA1 |
| symfony/ai#2889 | pull request | merged | 2026-11-06 | none after v1.0.0-BETA1 |
| symfony/ai#2891 | pull request | merged | 2026-11-06 | none after v1.0.0-BETA1 |
| symfony/ai#2963 | pull request | merged | 2026-11-19 | none after v1.0.0-BETA1 |
| symfony/symfony#67911 | pull request | merged | 2026-11-06 | v8.2.0-BETA2 |
| symfony/symfony#67912 | pull request | merged | 2026-11-06 | v8.2.0-BETA2 |
| symfony/symfony#67913 | pull request | merged | 2026-11-06 | v8.2.0-BETA2 |
| doctrine/DoctrineBundle#2335 | pull request | merged | 2026-11-13 | none (newest release 3.4.2, 2026-11-15) |
| rectorphp/rector-src#9012 | pull request | closed, not merged | 2026-11-21 | |
| symfony/ai#2890 | pull request | open, approved 2026-11-21 | | |
| doctrine/dbal#7633 | pull request | open, no maintainer review | | |
| symfony/symfony#68307 | pull request | open, no review, 14 checks green | | |
| symfony/symfony#68310 | pull request | open, no review, 15 checks green | | |
| symfony/ai#2944 | issue | open, no replies | | |
| symfony/ai#2945 | issue | open, no replies | | |
| symfony/ai#2962 | issue | open, no replies | | |
| symfony/ai#2617 | comment on an open pull request | open, no reply | | |

Threads others filed that the record names, unchanged: php/frankenphp#2479 open, php/frankenphp#2647 open draft (FrankenPHP v1.14.0 came out on 2026-11-21; rerun below), phpstan/phpstan#14838 and #14841 open.

`bin/highlights` shows each updated row replacing its earlier row in place.

### DBAL revision (doctrine/dbal 4.6.x 6a1c4a5e4, PHP 8.5.12, run as a regular user, `ci/github/phpunit/*.xml`)

| Run | `pdo_sqlite.xml` | `sqlite3.xml` | `pdo_sqlite-stringify_fetches.xml` |
|---|---|---|---|
| 3 new tests, without the `src/` change | 2 of 3 fail (`testRefusedCommit` error, `testTransactionalWithRefusedCommit` failure) | same | same |
| 3 new tests, with the change | OK (3 tests, 7 assertions) | OK | OK |
| full suite, with the change | OK, 4250 tests, 649 skipped, 13 incomplete | OK, 4250 tests, 648 skipped, 13 incomplete | OK, 4250 tests, 649 skipped, 13 incomplete |
| old `assertSame([2], ...)`, with the change | passes | not run | fails: `'2'` instead of `2` |

PHPStan (`phpstan.neon.dist`) and PHP_CodeSniffer: no errors on `src/Connection.php` and `tests/Functional/TransactionTest.php`.

### FrankenPHP v1.14.0

Release asset `frankenphp-linux-x86_64` (sha256 matches the digest GitHub lists), in a temporary folder first on `PATH`; `FrankenPHP v1.14.0 PHP 8.6.0 Caddy v2.11.9`. `vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php upstream/frankenphp-messenger-consume-sigterm`: 7 tests, 2 failures, exit 1; both `frankenphp php-cli` cases `Failed asserting that 139 is identical to 0`, plain `php` passes. Not fixed, so nothing in Intakt changes.

### New reproductions at the pinned install

`php bin/console intakt:upstream:run <folder with copies of the two new folders> -v` (symfony/framework-bundle and symfony/messenger v8.2.0-BETA2):

```
still fails  framework-debug-config-stale-container
exception: [critical] Error thrown while running command "debug:config framework default_locale". Message: "Can not change config enabled because it has already been read."
still fails (exit 1)
still fails  messenger-concurrency-limit-hides-fetched-message
The stopped worker handled: 1
The next worker got nothing: message 2 stays delivered and hidden until redeliver_timeout (3600 s).
REPRODUCTION FAILS
2 reproductions, 2 still fail
```

The same run with `APP_DEBUG=0` set in the caller prints the same lines. With the one `ConfigDebugCommand.php` line of symfony/symfony#68310 added to a scratch copy of `vendor/` (restored afterwards), the debug:config `reproduce.php` prints `REPRODUCTION PASSES`. No `debug-config-stale-*` folder is left in the temporary directory after the runs.

Not done for #3020: the `docs/architecture/requirements.md` `php` row (the journey's PHP uses), so it is referenced, not closed.

Gates `reproductions`, `highlights`, `dashes`, `commits`, `cs`, `comments` and `left-undone` pass (`bin/check --only=<gates> --base=origin/main`).

## Maintainer decisions (2026-11-22)

The reviews of 875e0227 were right on every point; all are documentation fixes, included in this branch:

1. Claude P2: `docs/video.md` now counts 30 reports with 20 filed, records the Rector report as filed as a pull request and declined upstream (rectorphp/rector-src#9012), and says none is `ready to file`.
2. GPT P1: `upstream/config-nan-skips-validation/REPORT.md`, `upstream/messenger-consume-ignores-early-sigterm/REPORT.md` and `upstream/serializer-object-collection-accepts-any-object/REPORT.md` get the `Checked 2026-11-22` note on their status line (still merged and in v8.2.0-BETA2, per the Evidence table above).
3. GPT P2: the `Form` line of `upstream/rector-parallel-argument-list/REPORT.md` is labelled as the original draft, superseded by the Decision line.
4. Claude P3: the counts row of `docs/highlights.d/3365-upstream-state-2026-11-22.md` says 4 pull requests and 3 issues open separately; the `Checked 2026-11-22` release note of `upstream/ai-claude-code-platform-cache-token-split/REPORT.md` sits next to the merge sentence.
5. The second reviews on #3373 judged 875e0227 again, not its fixed head: the Claude P2 on `docs/video.md` and the GPT P1 on the three status lines are resolved as in items 1 and 2 (see those lines on this head), so they are stale.
6. GPT P2 (second review): `upstream/framework-debug-config-stale-container/REPORT.md` and `upstream/messenger-concurrency-limit-hides-fetched-message/REPORT.md` run `reproduce.php` from its own folder, so the command now reads `php -d auto_prepend_file=../../vendor/autoload.php reproduce.php`.
7. Claude P3 (second review, the author lines in the two new `fix.patch` files): kept. Each `fix.patch` is the patch as filed (`gh pr diff --patch` of the owner's public upstream pull request), and its author line is the one shown on that pull request; rewriting it would make the file differ from what was filed.

Reviewers: judge the current head; a finding that repeats one of these without pointing at code on this head is stale.
