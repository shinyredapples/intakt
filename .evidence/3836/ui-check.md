# UI check of History and Trends (part 2 of #3836)

A scratch install on SQLite (`APP_ENV=test`, `DASHBOARD_PORT=7791`, `INTAKT_COMPOSE_SERVICE=intakt`), served by `php -d variables_order=EGPCS -S localhost:7791 -t public public/index.php`:

```
bin/console doctrine:migrations:migrate -n
bin/console intakt:demo:create
bin/console intakt:demo:replay fixtures/demo/sample-change-review.json --factor=1000
 [OK] Replayed 14 ledger events and 26 live updates of change sample-change into
      the demo project demo-replay, 1000 times as fast as recorded.
node bin/ui-check http://localhost:7791 > .evidence/3836/ui-check.txt
```

The History page had 14 rows of replayed events ("Judge done", "Usage recorded", ...) and Trends had its charts when checked.

`ui-check.txt` is the full output (exit 0). The `history` and `trends` lines, at 1440, 1920 and 390 wide in both themes, show `axe critical 0, serious 0`, and the 390 lines show `phone floor findings 0` (no text under 15 px, no touch target under 44 px). The last line: `UI check: 120 runs, 0 serious or critical axe violations, 0 phone floor findings, 0 page errors.`

`bin/ui-check` reads a chart label's font size before the SVG is scaled to its column, so it cannot see the shrink. A separate measurement (computed font size times the SVG's screen scale, over every `svg text` on `/trends`) found a defect: at 390 wide the 15 px labels rendered at 14.92 px. The fix is `.c-axis, .c-label { font-size: calc(var(--t15) * 1.07); }` in the phone rules of `assets/styles/components.css`, with `tests/Core/Dashboard/UseCase/ChartLabelStylesTest.php`. After it, `chart-label-rendered.txt` reads `390 labels 29 min rendered px 15.91` (before: 14.92), and `ui-check.txt` was produced again after the fix.

`assets/vendor/` (git-ignored) was filled from the npm registry's `@hotwired/stimulus@3.2.2` and `@hotwired/turbo@8.0.23`, and axe-core came from npm, because the importmap CDN is not reachable from the build sandbox.

Designer review: for the owner, on the pull request
