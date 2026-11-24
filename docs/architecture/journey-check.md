# Journey check

`bin/journey-check` walks user journeys in a real browser against a running Intakt and reports each one as passed or failed, with the step where it broke and a screenshot where one can be saved. It is a developer check run by hand: `bin/check` does not call it, and it is not the same as `bin/check --journeys` (see [Journeys](journeys.md)), which boots a fresh instance per journey and drives it over HTTP. How to run it is in the [user manual](../reference/user-manual.md#walking-user-journeys-with-the-journey-check).

## The runner

The script is Node.js (CommonJS) beside `bin/ui-check` and follows its contract: Playwright comes from `require('playwright')` on `NODE_PATH`, and `CHROME_PATH` names a Chrome or Chromium to launch in place of Playwright's own. It adds no PHP or npm dependency. `main(argv)` reads as its steps:

1. `parseAddress` parses the only argument with `new URL` (after `URL.canParse`), refuses anything but `http:` and `https:`, and clears `username`, `password`, `search` and `hash`, so a route appended to the address never lands in a query or fragment. Only that address is used from then on, for the requests, the browser and every line of output, so a user name or password given in the address never shows. A missing or unparsable address stops the check without echoing its text.
2. `expectAnswer` fetches `<address>/` with `signal: AbortSignal.timeout(STEP_TIMEOUT_MS)` (10 seconds) and without following redirects. Any HTTP status counts as an answer; a refused connection, or a server that accepts the connection and never answers, stops the check with `nothing answers at <address> within 10 s`.
3. Playwright is loaded and Chromium launched only after that, so the startup errors need no browser.
4. Each journey runs through `runJourney(browser, address, journey, folder, position)`, where `position` is its 1-based place in the run, in a new browser context (fresh cookies and storage), with `STEP_TIMEOUT_MS` as the page's default timeout. The steps run in order; the first one that throws ends the journey, and the first line of its error is kept (Playwright's call log follows on later lines).
5. `report` prints one line per journey and a count; `exitCode` gives 0 when every journey passed and 1 when one failed. Every error that escapes `main` (a startup failure, a missing Playwright, a browser that does not launch) prints `Journey check stopped: <reason>` and ends with exit code 2.

The journeys the check walks are the `JOURNEYS` list in the script. It is empty until part 2 of #3677 adds the first journey; the runner is proved by a trivial journey that only `tests/Core/Dashboard/UseCase/journey-check.browser.mjs` uses.

## Journeys and steps

A journey is `{ name, steps }`, and a step is `{ name, run(page, address) }`. The script exports three step builders, so a journey finds what a person sees and never a CSS class: a redesign keeps the journeys, while a renamed button fails the journey a person would also get stuck in.

| Builder | Step name | What it does |
|---|---|---|
| `open(path)` | `open <path>` | `page.goto(address + path)`; when no page was loaded (`goto` returns no response) the step fails with `no page was loaded for <path>`, and a response that is not a success fails it with `HTTP <status> for <path>` |
| `press(role, name)` | `press the <role> "<name>"` | `page.getByRole(role, { name, exact: true }).click()` |
| `see(text)` | `see "<text>"` | `page.getByText(text, { exact: true }).filter({ visible: true }).first().waitFor()`: waits for the first visible exact match, so a hidden match earlier in the page is skipped |

`exact: true` keeps `press('button', 'Start')` from matching a button named "Start review".

## Output

The screenshots of one run go to `var/journey-check/<run>/`, where `<run>` is the run's start in UTC as `toISOString()` with `:` and `.` replaced by `-` followed by `-` and eight random hex digits (for example `2026-11-24T09-05-07-012Z-1a2b3c4d`). The random suffix keeps runs that start in the same millisecond apart in practice; nothing checks that the folder is new. `screenshotFile(folder, position, name)` names the file: the journey's position in the run, zero-padded to two digits, then `-` and the journey's name in lower case with every other run of characters turned into `-` (`01-start-a-review.png`), so two journeys whose names reduce to the same text (`Read a verdict` and `Read a verdict!`) still get their own screenshot. The folder is made only when a journey fails, so a passing run leaves none. When the folder cannot be created or `page.screenshot` fails, the journey keeps its failed step and exit code 1, and the report says `no screenshot: <reason>` instead of the path.

```
passed <journey>
failed <journey> at step "<step>": <first line of the error>
  screenshot: var/journey-check/<run>/<position>-<journey>.png
Journey check: <n> journeys, <passed> passed, <failed> failed.
```

## Tests

- `tests/Core/Dashboard/UseCase/journey-check.test.mjs` runs without a browser: the address parsing and its redaction, the startup deadline against a `node:http` server on port 7781 that never answers, the exit code 2 cases through the script itself, the run's name, the screenshot names of two journeys whose names reduce to the same text, the report, and a failed journey whose folder cannot be created or whose page cannot take a screenshot (a stand-in browser).
- `tests/Core/Dashboard/UseCase/journey-check.browser.mjs` runs the trivial journey in Chromium, with its page answered by Playwright's routing on each context the runner opens, in four tests: a passing journey, a renamed button that fails its step with a screenshot, a fresh page for each journey, and a page where a hidden copy of the text comes first and `see` still passes on the visible copy that follows.

Both run by hand with `node --test` (see [Requirements](requirements.md)).
