# History and Trends against the mock (part 1 of #3836)

Files: `<page>-<theme>-<width>-build.png` beside `<page>-<theme>-<width>-mock.png`, for page history and trends, theme light and dark, width 1440, 1920 and 390 (24 images, committed with `git add -f` because `.evidence/` is git-ignored).

Capture rules, the same as `docs/design/mocks/tools/screenshots.js` uses for its mock images: viewport 1440x900 full page, 1920x1080 full page (the generator has no History entry at 1920, so the mock was shot with the same call at the generator's 1920 entry size), 390x844 viewport only (not full page). The build side uses the same viewports and the same `colorScheme`.

The build runs on SQLite with the ledger seeded by appending events through the Ledger service: two owner changes, one red head and four review rounds with two reviewers each. Names and counts differ from the mock by data, which is not a defect.

## What differs from the mock

- Ledger: same parts as the mock (title and subtitle, Ledger/Trends sub-tabs, filter chips, text filter, table with When, Source, What, Change, Until; on 390 the stacked list rows with the chips scrolling sideways). The When column prints the absolute UTC time and What the event kind with its project, where the mock shows "Today 11:02" and a sentence. Both are the shipped page's own wording rules (tests in `tests/Core/Dashboard/UseCase/ShowHistoryControllerTest.php`), so they are the page's content (#3835), not a layout defect.
- Trends: the sub-tabs, theme colors and component styles match. The content differs: the page shows the review panel's bench result, three figures, checked findings and Up next, where the mock draws queue, merge, cycle time and rework charts and the 7 days/14 days/8 weeks chips. That is the page's content (#3835).
- Shell: the rail reads Now, You, History, Projects where the mock reads Board, Waiting on you, Projects, Team, History, Settings. Shell content, outside this part.
- Trends has no visible History title above the sub-tabs, where `docs/design/mocks/trends.html` shows one. The page's h1 is screen-reader only, a deliberate choice of the page (#3835) covered by `testThePageNameStaysAHiddenHeadingAndTheBenchSentenceIsNotTheH1` in `tests/Core/Dashboard/UseCase/ShowTrendsControllerTest.php`; flagged for the owner's Designer review.

## The exact capture script

Run as `MOCKS=docs/design/mocks node shoot.js .evidence/3836 http://localhost:7780` (Playwright with the headless Chromium shell; the script lives outside the repository). It shoots both sides per page, theme and size: 1440x900 full page, 1920x1080 full page (the 1920 mock images come from this same call), 390x844 viewport only.

```js
// usage: node shoot.js <out dir> <build address>
const { chromium } = require('/opt/playwright/node_modules/playwright');
const path = require('path');
const [out, addr] = process.argv.slice(2);
const mocks = path.resolve(process.env.MOCKS);
const sizes = [{ w: 1440, h: 900, full: true }, { w: 1920, h: 1080, full: true }, { w: 390, h: 844, full: false }];
(async () => {
  const browser = await chromium.launch();
  for (const page of ['history', 'trends'])
    for (const theme of ['light', 'dark'])
      for (const s of sizes)
        for (const side of ['build', 'mock']) {
          const ctx = await browser.newContext({ viewport: { width: s.w, height: s.h }, colorScheme: theme });
          const p = await ctx.newPage();
          await p.goto(side === 'mock' ? `file://${mocks}/${page}.html` : `${addr}/${page}`, { waitUntil: 'networkidle' });
          await p.screenshot({ path: `${out}/${page}-${theme}-${s.w}-${side}.png`, fullPage: s.full });
          await ctx.close();
        }
  await browser.close();
})();
```
