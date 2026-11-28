# Evidence for #3836: History and Trends against the mock, axe and the keyboard

Install: `php -S localhost:7780 -t public public/index.php` on SQLite (PHP 8.5.12), migrated, with the recorded demo review replayed into it
(`INTAKT_COMPOSE_SERVICE=intakt`, `bin/console intakt:demo:create`, `bin/console intakt:demo:replay --factor=1000 fixtures/demo/sample-change-review.json`),
so History lists 14 ledger rows of `demo-replay` and Trends has figures. Browser: Playwright 1.64.1 with Chromium 156, axe-core 4.14.0 (`NODE_PATH` names them).

- `ui-check.txt`: `node bin/ui-check http://localhost:7780`, every page, 120 runs.
- `screens/<page>-<theme>-<width>.jpg`: the mock (left) beside the built page (right), History and Trends, light and dark, 1440, 1920 and 390.
- `keyboard-walk-through.txt`: the D5 walk and a Tab sweep of History and Trends at 1440x900 and 390x844.

## How the mock images were made

`docs/design/mocks/tools/screenshots.js` is unchanged. `tools/mock-pairs.js` shoots `docs/design/mocks/history.html` and `trends.html` as the
generator does (a new context with `viewport` and `colorScheme`, `waitForLoadState('networkidle')`, `document.fonts.ready`), at the generator's
sizes 1440x900 and 390x844 and at 1920x1080, the viewport of the generator's 1920 entry (`WIDE[0]`), all as full pages, then puts each beside
`var/ui-check/<page>-<theme>-<width>.png` from the UI check run:

    NODE_PATH=<playwright folder> node .evidence/3836/tools/mock-pairs.js . <out folder>

The keyboard walk drives the pages with key presses only (`page.evaluate` only reads state):

    NODE_PATH=<playwright folder> node .evidence/3836/tools/keyboard-walk.js http://localhost:7780
