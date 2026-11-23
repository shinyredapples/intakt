## Journey check

`bin/journey-check [address]` walks user journeys through a running dashboard in a headless browser, the way a person clicks through it, and reports each journey as passed or failed (#3677, part 1 of #3611). It complements the [journeys](journeys.md) of `bin/check --journeys`, which drive a fresh instance through Symfony's test browser without JavaScript: here the pages run their scripts and live updates, so a step that needs the hub or the caption script is walked as a visitor sees it.

### Running it

It is a Node.js script (CommonJS) beside `bin/ui-check`, with the same contract: Node.js 22 or later and Playwright on `NODE_PATH`, Playwright's Chromium or the Chrome or Chromium `CHROME_PATH` names ([requirements](requirements.md)). `bin/check` does not run it.

```sh
npm install --global playwright
npx playwright install chromium
NODE_PATH="$(npm root --global)" bin/journey-check http://localhost:7780
```

The address defaults to `http://localhost:7780`. Each journey runs in a fresh browser context, so no cookie, storage or page of one journey reaches the next. Its steps run in order and the journey stops at the first step that fails; a click or a wait gives up after 15 seconds, and the steps that wait for the recorded demo run after 180 seconds.

- **Output:** one line per journey (`<journey>: passed`, or `<journey>: failed at step <n>, <step>` with the reason and the screenshot under it) and a summary line `Journey check: <n> journeys, <passed> passed, <failed> failed.`
- **Screenshots:** a failed step saves a full-page screenshot as `var/journey-check/<journey>-step-<n>.png` (the journey name in lowercase, with dashes). A run first removes the screenshots of the last run, once the address answers.
- **Exit code:** 0 when every journey passed, 1 when one failed, 2 when the check could not run (nothing answers at the address, Playwright is missing or the browser does not start).

### Selectors

The steps find controls only by role and visible text (`getByRole`, `getByText`), never by a CSS class, id or `data-*` attribute. A redesign keeps the journeys, and a renamed button fails the journey a person would also miss it in. `tests/Core/Dashboard/UseCase/journey-check.test.mjs` walks every journey over a recording stand-in page and fails when a step calls anything else.

### The journeys

1. **First run without a key** needs an install that offers the demo run and has not played it yet (the compose demo with its shipped recording, see [the demo](../reference/user-manual.md#the-demo-with-docker-compose)); a played demo fails it at step 2, since `/setup` then offers no Play button.
   1. `/setup shows See a real review`: the level-1 heading **See a real review**.
   2. `Play the demo run under See a real review leads to /demo`: the button **Play the demo run** in the region named **See a real review** is pressed (it starts the replay), and `/demo` shows the heading **The demo run**.
   3. `the recording plays onto the dashboard`: the page follows the run to `/reviews/<round>` (`assets/tour-caption.js`), which shows the **Demo tour** caption.
   4. `the run reaches its verdict`: the verdict region is named after an outcome (**Approved**, **Changes needed**, **Blocked:** or **Failed:**).
   5. `the review page shows the findings`: the **To fix** region holds at least one finding link.

### Tests

- `tests/Core/Dashboard/UseCase/journey-check.test.mjs` (`node --test`): the runner over stand-in browsers: passed and failed journeys, the step named and the later steps skipped, the screenshot's name, a fresh context per journey, the report and the exit code, and the selector rule.
- `tests/Core/Dashboard/UseCase/journey-check.browser.mjs` (`node --test`, Playwright): the first-run journey over a stand-in of the three pages served by `node:http`; it passes as rendered, and with the button renamed to **Play the demo** it fails at step 2 with its screenshot.
