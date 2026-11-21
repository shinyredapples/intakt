Evidence for #1460, rebuilt on commit 7a315b037 (the last product-code commit of the branch).
The later merge of main (ea14ef18b) changes no board file: only the review page's reviewer
model cells (_reviewer_cells, _reviewer_model_cell, _now_reviewer, round_changed) and FindingText.
ui-check.txt was rerun on head 6ecb3b363 (the merge of the current main, which changes no file
under src, templates or assets) on the same kind of install: change 41 judged, change 42 with two
reviewers working, both with a model on their reviewers, added through the Ledger contract.

Install: APP_ENV=test, APP_DEBUG=0, a fresh SQLite database migrated with doctrine:migrations:migrate,
served by PHP's built-in server on localhost:7780 (DASHBOARD_PORT). The ledger holds two rounds:
change 41 started and judged (Done holds one card, starts folded), change 42 started with two
reviewers working (In review holds one card). Nothing on the page was seeded or edited in the
browser, except the spacer in phone.txt that only moves the switcher near the bottom of the view.

The import map's vendor files (@hotwired/stimulus 3.2.2, @hotwired/turbo 8.0.23) come from the
npm registry, because the CDN importmap:install uses is not reachable from the build sandbox;
asset-map:compile wrote public/assets. Both folders are git-ignored.

Files:
- board-built-<theme>-<width>.png beside board-mock-<theme>-<width>.png (mock: docs/design/mocks/tools/screenshots.js, index-*.png)
- board.txt: map, board widths, sideways scroll and switcher at 1440, 1920 and 390, light and dark (E1)
- ui-check.txt: axe and the 390 floors over every page of bin/ui-check, with bin/ui-check's own
  jobsFor, measureFloors and report; viewport only, because a full-page shot of /components ran
  out of the build's memory (E2, E4)
- walkthrough.txt: keyboard walk-through at 1440, 839 and 907 (E3, E5, E6)
- walk-<width>-after-escape.png: the board after the Escape fold
- phone.txt, phone-tap-done-near-bottom.png: phone tap with the switcher near the bottom (E3)
