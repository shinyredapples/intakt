Evidence for #1460, built on commit 05ec2d844 (the product code of this pull request; later commits
only add these files).

Install: a fresh checkout of 05ec2d844, composer install, importmap:install, asset-map:compile,
doctrine:migrations:migrate on a new SQLite database (APP_ENV=test, APP_DEBUG=0), the sample
repository built with intakt:sample:seed, the demo project made with intakt:demo:create and the
recorded review fixtures/demo/sample-change-review.json replayed with intakt:demo:replay. Served by
PHP's built-in server on localhost:7780 (DASHBOARD_PORT). Nothing was seeded or edited in the
browser. The replayed round is judged, so the board holds one card in Done, which starts open
because every other column is empty, and nobody is working.

Files:
- board-built-<theme>-<width>.png beside board-mock-<theme>-<width>.png (mock:
  docs/design/mocks/tools/screenshots.js). 1440 and 1920 are full pages; 390 is the first phone
  screen, since a full-page shot resizes the view and moves the board's sideways scroll.
- review-built-<theme>-<width>.png: the review detail page of the replayed round, 390 and 1440.
- walkthrough.txt: map, switcher and segment sizes, the keyboard walk-through at 839 wide, and the
  phone tap and swipe (E1, E3, E5, E6).
- walk-839-after-escape.png: the board after Escape folds Done.
- ui-check.txt: bin/ui-check over every page, 3 widths, both themes (E2, E4).
