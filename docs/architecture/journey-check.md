# Journey check

`bin/journey-check [address]` walks user journeys through a running dashboard in a headless browser, the way a person clicks through it, and reports each journey as passed or failed (#3677, part 1 of #3611). It complements the [journeys](journeys.md) of `bin/check --journeys`, which start a fresh instance for each journey and drive it through Symfony's test browser without JavaScript. `bin/journey-check` starts nothing: it walks an Intakt that is already running (the compose demo), and there the pages run their scripts and live updates, so a step that needs the hub or the caption script is walked as a visitor sees it.

## Running it

It is a Node.js script (CommonJS) beside `bin/ui-check`, with the same contract: Node.js 22 or later and Playwright on `NODE_PATH`, Playwright's Chromium or the Chrome or Chromium `CHROME_PATH` names ([requirements](requirements.md)). `bin/check` does not run it.

```sh
npm install --global playwright
npx playwright install chromium
NODE_PATH="$(npm root --global)" bin/journey-check http://localhost:7781
```

The address defaults to `http://localhost:7781`, where the compose demo publishes the dashboard. Each journey runs in a fresh browser context, so no cookie, storage or page of one journey reaches the next. Its steps run in order and the journey stops at the first step that fails; a click or a wait gives up after 15 seconds, and the steps that wait for the recorded demo run after 180 seconds.

- **Output:** one line per journey (`<journey>: passed`, or `<journey>: failed at step <n>, <step>` with the reason and the screenshot under it) and a summary line `Journey check: <n> journeys, <passed> passed, <failed> failed.`
- **Screenshots:** a failed step saves a full-page screenshot as `var/journey-check/<run>/<journey>-step-<n>.png` (the journey name in lowercase, with dashes). `<run>` is the run's start time in UTC and its process id (for example `20261123T101500Z-4242`), so two runs at once never remove or overwrite each other's screenshots. A run creates its folder only when a step fails and removes no folder; delete old ones by hand. When the folder cannot be created or the page cannot be captured, the report says `screenshot: none, the page could not be captured` and the journey still fails with its step (exit 1).
- **Exit code:** 0 when every journey passed, 1 when one failed, 2 when the check could not run (nothing answers at the address, Playwright is missing or the browser does not start).

## Selectors

The steps find controls only by role and visible text (`getByRole`, `getByText`), never by a CSS class, id or `data-*` attribute. A redesign keeps the journeys, and a renamed button fails the journey a person would also miss it in. Every name is matched exactly (`exact: true`), because Playwright otherwise matches a substring and a button renamed to **Start: Play the demo run** would still pass; a name given as a pattern is anchored at its start. `tests/Core/Dashboard/UseCase/journey-check.test.mjs` walks every journey over a recording stand-in page and fails when a step calls anything else or looks for a name that is not exact.

## The journeys

1. **First run without a key** needs an install that offers the demo run and has not played it yet (the compose demo with its shipped recording, see [the demo](../reference/user-manual.md#the-demo-with-docker-compose)); a played demo fails it at step 2, since `/setup` then offers no Play button.
   1. `/setup shows See a real review`: the level-1 heading **See a real review**.
   2. `Play the demo run under See a real review leads to /demo`: the button **Play the demo run** in the region named **See a real review** is pressed (it starts the replay), and `/demo` shows the heading **The demo run**.
   3. `the recording plays onto the dashboard`: the page follows the run to `/reviews/<round>` (`assets/tour-caption.js`), which shows the **Demo tour** caption.
   4. `the run reaches its verdict, Changes needed`: it waits up to 180 seconds for the verdict region to be named after any outcome (**Approved**, **Changes needed**, **Blocked:** or **Failed:**), then requires the shipped recording's verdict, exactly **Changes needed**; a replay that failed or was blocked fails here.
   5. `the review page shows the recorded finding to fix`: the **To fix** region holds the link of the recording's first finding, whose name starts with **The check changed from**, then `amount >= 100`, then **to**, then `amount > 100` followed by any character that is not a letter, digit or underscore (so `amount > 100.` and `amount > 100.50` both match, `amount > 1000` does not); the shipped recording's finding reads **The check changed from amount >= 100 to amount > 100.** Any other link there does not count.

## Tests

- `tests/Core/Dashboard/UseCase/journey-check.test.mjs` (`node --test`): the runner over stand-in browsers: passed and failed journeys, the step named and the later steps skipped, the screenshot's name, a step still reported when its screenshot cannot be saved, a fresh context per journey, the run's own folder, the report and the exit code, and the selector rules (role and text only, exact names, anchored patterns).
- `tests/Core/Dashboard/UseCase/journey-check.browser.mjs` (`node --test`, Playwright): the first-run journey over a stand-in of the three pages served by `node:http`. It passes as rendered; with the button renamed to **Play the demo** or to **Start: Play the demo run** it fails at step 2 (with its screenshot), with the verdict **Failed:** at step 4, and with an unrelated link in **To fix** instead of the finding at step 5.
