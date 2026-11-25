# Evidence of issue #3014 (Trends chart 2, what the judge did)

## Running install

A scratch install on SQLite (`APP_ENV=test`, `DASHBOARD_PORT=7791`, `INTAKT_COMPOSE_SERVICE=intakt` so the demo commands run), served by `php -S localhost:7791 -t public public/index.php`:

```
bin/console doctrine:migrations:migrate -n
bin/console intakt:demo:create
 [OK] The demo project demo-replay is marked as a replay: its reviews never count as a verdict.
bin/console intakt:demo:replay fixtures/demo/sample-change-review.json --factor=1000
 [OK] Replayed 14 ledger events and 26 live updates of change sample-change into
      the demo project demo-replay, 1000 times as fast as recorded.
```

The replayed rows are the ledger rows of a real review that `intakt:demo:record` captured, so `/trends` reads rows the application wrote, not rows appended by hand. Playwright's Chromium then opened `/trends` (HTTP 200 in all four runs) and saved:

- `trends-dark-1440.png`, `trends-light-1440.png`, `trends-dark-390.png`, `trends-light-390.png` (full page; the bar across the middle is the demo tour caption, fixed to the viewport).
- `trends-dark-1440-judge-keyboard-tooltip.png`: the first bar of "Checked findings" focused with the keyboard; the tooltip reads "5 findings / Kept to fix".
- `trends-tables.txt`: the heading, takeaway and table of each chart as the page renders them.
- `ui-check.txt`: `node bin/ui-check http://localhost:7791` on the same install (exit 0).

The `importmap:install` CDN is not reachable from the build sandbox, so `assets/vendor/` (git-ignored) was filled from the npm registry's `@hotwired/stimulus@3.2.2` and `@hotwired/turbo@8.0.23`, the versions `importmap.php` pins.

## Decision 5: a fallback round adds nothing to Rejected

Source commit `ef3ca6aeb` (maint/2934-trends-charts) with this branch's test file:

```
vendor/bin/phpunit tests/Core/Dashboard/UseCase/TrendsFromAReviewRoundTest.php --filter testARoundTheJudgeCouldNotAnswerAddsNothingToTheJudgeChart
1) ...TrendsFromAReviewRoundTest::testARoundTheJudgeCouldNotAnswerAddsNothingToTheJudgeChart
Failed asserting that two strings are identical.
-'The judge kept 1 of 3 findings and rejected 1.'
+'The judge kept 2 of 6 findings and rejected 3.'
FAILURES! Tests: 1, Assertions: 4, Failures: 1.

vendor/bin/phpunit tests/Core/Dashboard/Domain/JudgeOutcomesTest.php --filter testAFallbackRoundIsLeftOutSoItsUnmarkedP3AddsNothingToRejected
Error: Call to undefined method Intakt\Core\Dashboard\Domain\ReviewedRound::judgedOnItsOwnMarks()
ERRORS! Tests: 1, Assertions: 0, Errors: 1.
```

This branch:

```
vendor/bin/phpunit tests/Core/Dashboard/Domain/JudgeOutcomesTest.php
OK (12 tests, 13 assertions)
vendor/bin/phpunit tests/Core/Dashboard/UseCase/TrendsFromAReviewRoundTest.php
OK (2 tests, 12 assertions)
```
