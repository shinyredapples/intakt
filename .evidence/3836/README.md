# History and Trends against the mock (part 1 of #3836)

Files: `<page>-<theme>-<width>-build.png` beside `<page>-<theme>-<width>-mock.png`, for page history and trends, theme light and dark, width 1440, 1920 and 390 (24 images, the PNGs stay local because `.evidence/` is git-ignored).

Capture rules, the same as `docs/design/mocks/tools/screenshots.js` uses for its mock images: viewport 1440x900 full page, 1920x1080 full page (the generator has no History entry at 1920, so the mock was shot with the same call at the generator's 1920 entry size), 390x844 viewport only (not full page). The build side uses the same viewports and the same `colorScheme`.

The build runs on SQLite with the ledger seeded by appending events through the Ledger service: two owner changes, one red head and four review rounds with two reviewers each. Names and counts differ from the mock by data, which is not a defect.

## What differs from the mock

- Ledger: same parts as the mock (title and subtitle, Ledger/Trends sub-tabs, filter chips, text filter, table with When, Source, What, Change, Until; on 390 the stacked list rows with the chips scrolling sideways). The When column prints the absolute UTC time and What the event kind with its project, where the mock shows "Today 11:02" and a sentence. Both are the shipped page's own wording rules (tests in `tests/Core/Dashboard/UseCase/ShowHistoryControllerTest.php`), so they are the page's content (#3835), not a layout defect.
- Trends: the sub-tabs, theme colors and component styles match. The content differs: the page shows the review panel's bench result, three figures, checked findings and Up next, where the mock draws queue, merge, cycle time and rework charts and the 7 days/14 days/8 weeks chips. That is the page's content (#3835).
- Shell: the rail reads Now, You, History, Projects where the mock reads Board, Waiting on you, Projects, Team, History, Settings. Shell content, outside this part.
- Trends has no visible History title above the sub-tabs (the h1 is screen-reader only, covered by `testThePageNameStaysAHiddenHeadingAndTheBenchSentenceIsNotTheH1`).
