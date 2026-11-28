# Keyboard-only walk-through of History and Trends (part 3 of #3836)

The D5 flow, at 1440x900 and 390x844, on SQLite: open History, filter by project, switch to Trends, switch back. Only `Tab`, `Shift+Tab`, `Enter`, `Space`, `ArrowDown`, `ArrowRight`, `Escape` and typed letters are sent; the mouse is never used. The ledger and the Trends rounds were seeded with the script of `README.md` in this folder (project `alpha` of `config/intakt.example.yaml`), plus the History rows that seed appends.

Filter by project is the top bar's `#projfilter` at 1440 and the toolbar's own `#history-project` at 390 (the phone top bar has no filter). After each key the script reads `document.activeElement` and checks that the focus ring (the outline, or the stroke of a chart bar) lies inside the viewport and inside every ancestor that clips (`overflow` not `visible`), and that nothing covers the focused control's center.

## Result

- Every control of both pages is reached with Tab in a sensible order (rail or bottom bar, search, project filter, theme toggle, Ledger/Trends sub-tabs, then the page's own controls) and Shift+Tab walks back along the same stops.
- Enter and Space press a filter chip (`aria-pressed` flips, the rows narrow); Enter on a sub-tab link navigates to the other page and keeps the chosen project; ArrowDown on the project select picks `alpha`; typing in the text filter narrows the rows.
- Esc: the search overlay closes and focus returns to its opener (the search button); at 390 the More sheet closes and focus returns to More; an open native select list closes with focus kept on the select (the list belongs to the browser, headless Chromium draws no list, so the step shows only that focus stays); a Trends tile tip closes and focus stays on its term.
- Two defects on these pages found and fixed, each with a test:
  1. 390, History toolbar: the sideways-scrolling bar left a focused chip partly outside the bar (By Intakt, Accounts, also Everything and the text input on the way back), so its ring was clipped. Before the fix the walk printed `clipped right by div.filters` for By Intakt, Accounts and the text input, and `outside viewport sideways; clipped left by div.filters` for Accounts, By Intakt and Everything on Shift+Tab. Cause: the browser scrolls a partly visible control only when it is hidden entirely. Fix: `scroll-padding-inline: var(--s8)` on `.filters` and a `focusin` handler in `assets/history-filter.js` (tests `ComponentStylesTest::testOnPhonesTheFiltersBarKeepsAFocusedControlTheRingsWidthPlusOffsetAndPaddingFromItsScrollEdge`, `history-filter.test.mjs` "on a phone a control of the toolbar that takes focus is scrolled fully into view sideways" and two more).
  2. Trends tiles (and every other glossary term): the tip shown on focus could not be closed with Escape; before the fix the walk printed `tooltip shown` after Escape. Fix: `assets/term-dismiss.js` marks the term `data-dismissed` on Escape (focus stays), `.term[data-dismissed] .term-tip { display: none }`, re-armed when focus leaves (tests `term-dismiss.test.mjs`, `ComponentStylesTest::testADismissedTermHidesItsTipAfterTheRuleThatShowsItOnFocusAndHover`).
- Not fixed, recorded:
  - 390, row 11: with the More sheet open, the walk reports the focused More button as covered by `div.menu` (the sheet's backdrop spans the bar). The ring is drawn and Esc closes the sheet and returns focus. The shell belongs to no part of History or Trends: suggested follow-up for the shell.
  - 390, row 30: the History table region (`tabindex="0"`) is as tall as the whole ledger (about 9800 px with 65 rows), so only its edges are ever on screen and the fixed bottom bar covers the point the check samples. No ancestor clips its ring. At 1440 it is the same box, not covered. Left as it is: a ring around a region taller than the screen cannot be shown whole by any change to the page's own rules.
  - After Enter on a sub-tab link the page is a new visit, so focus starts at the top of the document (the walk shows `body`), and the next Tab reaches the brand link. Turbo's normal behavior.

## Walk-through, step by step

Columns: the key, the element that holds focus afterwards, the result read from the page, the ring's size, and whether the whole ring lies inside the viewport and every clipping ancestor. The Trends chart bars (SVG rects) show a 2 px stroke as their ring.

## 1440 wide

| # | Key | Focus lands on | Result | Ring | Whole ring visible |
|---|---|---|---|---|---|
| 1 | Tab | a.brand "Intakt home" |  | 2px +2px | whole ring visible |
| 2 | Tab | a.nav "Now" |  | 2px +2px | whole ring visible |
| 3 | Tab | a.nav "You" |  | 2px +2px | whole ring visible |
| 4 | Tab | a.nav "History" |  | 2px +2px | whole ring visible |
| 5 | Tab | a.nav "Projects" |  | 2px +2px | whole ring visible |
| 6 | Tab | button.nav "Collapse" |  | 2px +2px | whole ring visible |
| 7 | Tab | button.search-trigger "Search tasks, pull requests, settings… C" |  | 2px +2px | whole ring visible |
| 8 | Enter | input#search-q "Search" | dialog open: true | 2px +0px | whole ring visible |
| 9 | Escape | button.search-trigger "Search tasks, pull requests, settings… C" | palette open: false | 2px +2px | whole ring visible |
| 10 | Tab | select#projfilter.select "Project" |  | 2px +2px | whole ring visible |
| 11 | ArrowDown | select#projfilter.select "Project" | alpha chosen | 2px +2px | whole ring visible |
| 12 | Space | select#projfilter.select "Project" | opens the native list | 2px +2px | whole ring visible |
| 13 | Escape | select#projfilter.select "Project" | projfilter keeps focus | 2px +2px | whole ring visible |
| 14 | (rows) | select#projfilter.select "Project" | 64 rows shown | 2px +2px | whole ring visible |
| 15 | Tab | button.icon-btn "Switch light or dark theme" |  | 2px +2px | whole ring visible |
| 16 | Tab | a "Ledger" |  | 2px +2px | whole ring visible |
| 17 | Tab | a "Trends" |  | 2px +2px | whole ring visible |
| 18 | Tab | button.fchip "Everything" |  | 2px +2px | whole ring visible |
| 19 | Tab | button.fchip "From the dashboard" |  | 2px +2px | whole ring visible |
| 20 | Space | button.fchip "From the dashboard" | aria-pressed=true, 0 rows shown | 2px +2px | whole ring visible |
| 21 | Enter | button.fchip "From the dashboard" | aria-pressed=true, 0 rows shown | 2px +2px | whole ring visible |
| 22 | Tab | button.fchip "By Intakt" |  | 2px +2px | whole ring visible |
| 23 | Tab | button.fchip "Limits" |  | 2px +2px | whole ring visible |
| 24 | Tab | button.fchip "Projects" |  | 2px +2px | whole ring visible |
| 25 | Tab | button.fchip "Accounts" |  | 2px +2px | whole ring visible |
| 26 | Tab | input#history-text.input "Filter by text" |  | 2px +0px | whole ring visible |
| 27 | type "sess" | input#history-text.input "sess" | 0 rows shown | 2px +0px | whole ring visible |
| 28 | Tab | div.table-scroll "History" | scrollWidth 1136 client 1136 | 2px +2px | whole ring visible |
| 29 | ArrowRight | div.table-scroll "History" | scrollLeft 0 | 2px +2px | whole ring visible |
| 30 | Shift+Tab | input#history-text.input "Filter by text" |  | 2px +0px | whole ring visible |
| 31 | Shift+Tab | button.fchip "Accounts" |  | 2px +2px | whole ring visible |
| 32 | Shift+Tab | button.fchip "Projects" |  | 2px +2px | whole ring visible |
| 33 | Shift+Tab | button.fchip "Limits" |  | 2px +2px | whole ring visible |
| 34 | Shift+Tab | button.fchip "By Intakt" |  | 2px +2px | whole ring visible |
| 35 | Shift+Tab | button.fchip "From the dashboard" |  | 2px +2px | whole ring visible |
| 36 | Shift+Tab | button.fchip "Everything" |  | 2px +2px | whole ring visible |
| 37 | Shift+Tab | a "Trends" |  | 2px +2px | whole ring visible |
| 38 | Enter | body | URL /trends, top bar filter: alpha | none | whole ring visible |
| 39 | Tab | a.brand "Intakt home" |  | 2px +2px | whole ring visible |
| 40 | Tab | a.nav "Now" |  | 2px +2px | whole ring visible |
| 41 | Tab | a.nav "You" |  | 2px +2px | whole ring visible |
| 42 | Tab | a.nav "History" |  | 2px +2px | whole ring visible |
| 43 | Tab | a.nav "Projects" |  | 2px +2px | whole ring visible |
| 44 | Tab | button.nav "Collapse" |  | 2px +2px | whole ring visible |
| 45 | Tab | button.search-trigger "Search tasks, pull requests, settings… C" |  | 2px +2px | whole ring visible |
| 46 | Tab | select#projfilter.select "Project" |  | 2px +2px | whole ring visible |
| 47 | Tab | button.icon-btn "Switch light or dark theme" |  | 2px +2px | whole ring visible |
| 48 | Tab | a "Ledger" |  | 2px +2px | whole ring visible |
| 49 | Tab | a "Trends" |  | 2px +2px | whole ring visible |
| 50 | Tab | span.term "Passes Review passes started, whether ru" | tooltip shown | 2px +2px | whole ring visible |
| 51 | Escape | span.term "Passes" | tooltip hidden | 2px +2px | whole ring visible |
| 52 | Tab | span.term "To fix P1 and P2 findings the judge kept" | tooltip shown | 2px +2px | whole ring visible |
| 53 | Tab | span.term "One reviewer only Share of the findings " | tooltip shown | 2px +2px | whole ring visible |
| 54 | Tab | span.term "Time to verdict Median time from the sta" | tooltip shown | 2px +2px | whole ring visible |
| 55 | Tab | span.term "Cost per pass Mean of the passes with a " | tooltip shown | 2px +2px | whole ring visible |
| 56 | Tab | rect "2 P1 findings, Correctness reviewer, 0 f" |  | stroke 2px | whole ring visible |
| 57 | Tab | rect "3 P2 findings, Correctness reviewer, 3 f" |  | stroke 2px | whole ring visible |
| 58 | Tab | rect "3 P3 findings, Correctness reviewer, 3 f" |  | stroke 2px | whole ring visible |
| 59 | Tab | rect "2 P1 findings, Security reviewer, 0 foun" |  | stroke 2px | whole ring visible |
| 60 | Tab | rect "3 P2 findings, Security reviewer, 3 foun" |  | stroke 2px | whole ring visible |
| 61 | Tab | rect "3 P3 findings, Security reviewer, 3 foun" |  | stroke 2px | whole ring visible |
| 62 | Tab | rect "7 findings, Kept to fix" |  | stroke 2px | whole ring visible |
| 63 | Tab | rect "2 findings, Merged as duplicate" |  | stroke 2px | whole ring visible |
| 64 | Tab | rect "3 findings, Deferred to a follow-up issu" |  | stroke 2px | whole ring visible |
| 65 | Tab | rect "4 findings, Rejected" |  | stroke 2px | whole ring visible |
| 66 | Tab | span.term "Passes Counted by the day each pass star" | tooltip shown | 2px +2px | whole ring visible |
| 67 | Tab | rect "2 rounds, Nov 24" |  | stroke 2px | whole ring visible |
| 68 | Tab | rect "1 round, Nov 25" |  | stroke 2px | whole ring visible |
| 69 | Tab | rect "1 round, Nov 26" |  | stroke 2px | whole ring visible |
| 70 | Tab | rect "2 rounds, Nov 27" |  | stroke 2px | whole ring visible |
| 71 | Tab | rect "2 rounds, Nov 28" |  | stroke 2px | whole ring visible |
| 72 | Tab | rect "0.3150 USD, estimated, Nov 24" |  | stroke 2px | whole ring visible |
| 73 | Tab | rect "0.2160 USD, estimated, Nov 25" |  | stroke 2px | whole ring visible |
| 74 | Tab | rect "0.2850 USD, estimated, Nov 26" |  | stroke 2px | whole ring visible |
| 75 | Tab | rect "0.3015 USD, estimated, Nov 27" |  | stroke 2px | whole ring visible |
| 76 | Tab | rect "No data, Nov 28, no cost estimate" |  | stroke 2px | whole ring visible |
| 77 | Shift+Tab | rect "No data, Nov 28, no cost estimate" |  | stroke 2px | whole ring visible |
| 78 | Shift+Tab | rect "0.3015 USD, estimated, Nov 27" |  | stroke 2px | whole ring visible |
| 79 | Shift+Tab | rect "0.2850 USD, estimated, Nov 26" |  | stroke 2px | whole ring visible |
| 80 | Shift+Tab | rect "0.2160 USD, estimated, Nov 25" |  | stroke 2px | whole ring visible |
| 81 | Shift+Tab | rect "0.3150 USD, estimated, Nov 24" |  | stroke 2px | whole ring visible |
| 82 | Shift+Tab | rect "2 rounds, Nov 28" |  | stroke 2px | whole ring visible |
| 83 | Shift+Tab | rect "2 rounds, Nov 27" |  | stroke 2px | whole ring visible |
| 84 | Shift+Tab | rect "1 round, Nov 26" |  | stroke 2px | whole ring visible |
| 85 | Shift+Tab | rect "1 round, Nov 25" |  | stroke 2px | whole ring visible |
| 86 | Shift+Tab | rect "2 rounds, Nov 24" |  | stroke 2px | whole ring visible |
| 87 | Shift+Tab | span.term "Passes Counted by the day each pass star" |  | 2px +2px | whole ring visible |
| 88 | Shift+Tab | rect "4 findings, Rejected" |  | stroke 2px | whole ring visible |
| 89 | Shift+Tab | rect "3 findings, Deferred to a follow-up issu" |  | stroke 2px | whole ring visible |
| 90 | Shift+Tab | rect "2 findings, Merged as duplicate" |  | stroke 2px | whole ring visible |
| 91 | Shift+Tab | rect "7 findings, Kept to fix" |  | stroke 2px | whole ring visible |
| 92 | Shift+Tab | rect "3 P3 findings, Security reviewer, 3 foun" |  | stroke 2px | whole ring visible |
| 93 | Shift+Tab | rect "3 P2 findings, Security reviewer, 3 foun" |  | stroke 2px | whole ring visible |
| 94 | Shift+Tab | rect "2 P1 findings, Security reviewer, 0 foun" |  | stroke 2px | whole ring visible |
| 95 | Shift+Tab | rect "3 P3 findings, Correctness reviewer, 3 f" |  | stroke 2px | whole ring visible |
| 96 | Shift+Tab | rect "3 P2 findings, Correctness reviewer, 3 f" |  | stroke 2px | whole ring visible |
| 97 | Shift+Tab | rect "2 P1 findings, Correctness reviewer, 0 f" |  | stroke 2px | whole ring visible |
| 98 | Shift+Tab | span.term "Cost per pass Mean of the passes with a " |  | 2px +2px | whole ring visible |
| 99 | Shift+Tab | span.term "Time to verdict Median time from the sta" |  | 2px +2px | whole ring visible |
| 100 | Shift+Tab | span.term "One reviewer only Share of the findings " |  | 2px +2px | whole ring visible |
| 101 | Shift+Tab | span.term "To fix P1 and P2 findings the judge kept" |  | 2px +2px | whole ring visible |
| 102 | Shift+Tab | span.term "Passes Review passes started, whether ru" |  | 2px +2px | whole ring visible |
| 103 | Shift+Tab | a "Trends" |  | 2px +2px | whole ring visible |
| 104 | Shift+Tab | a "Ledger" |  | 2px +2px | whole ring visible |
| 105 | Enter | body | URL /history | none | whole ring visible |

## 390 wide

| # | Key | Focus lands on | Result | Ring | Whole ring visible |
|---|---|---|---|---|---|
| 1 | Tab | a.nav "Now" |  | 2px +2px | whole ring visible |
| 2 | Tab | a.nav "You" |  | 2px +2px | whole ring visible |
| 3 | Tab | a.nav "History" |  | 2px +2px | whole ring visible |
| 4 | Tab | button.nav "More" |  | 2px +2px | whole ring visible |
| 5 | Tab | a.brand-m "Intakt home" |  | 2px +2px | whole ring visible |
| 6 | Tab | button.search-trigger "Search" |  | 2px +2px | whole ring visible |
| 7 | Enter | input#search-q "Search" | dialog open: true | 2px +0px | whole ring visible |
| 8 | Escape | button.search-trigger "Search" | palette open: false | 2px +2px | whole ring visible |
| 9 | Shift+Tab | a.brand-m "Intakt home" |  | 2px +2px | whole ring visible |
| 10 | Shift+Tab | button.nav "More" |  | 2px +2px | whole ring visible |
| 11 | Enter | button.nav "More" | expanded=true | 2px +2px | covered by div.menu |
| 12 | Escape | button.nav "More" | expanded=false | 2px +2px | whole ring visible |
| 13 | Tab | a.brand-m "Intakt home" |  | 2px +2px | whole ring visible |
| 14 | Tab | button.search-trigger "Search" |  | 2px +2px | whole ring visible |
| 15 | Tab | button.icon-btn "Switch light or dark theme" |  | 2px +2px | whole ring visible |
| 16 | Tab | a "Ledger" |  | 2px +2px | whole ring visible |
| 17 | Tab | a "Trends" |  | 2px +2px | whole ring visible |
| 18 | Tab | button.fchip "Everything" |  | 2px +2px | whole ring visible |
| 19 | Tab | button.fchip "From the dashboard" |  | 2px +2px | whole ring visible |
| 20 | Tab | button.fchip "By Intakt" |  | 2px +2px | whole ring visible |
| 21 | Tab | button.fchip "Limits" |  | 2px +2px | whole ring visible |
| 22 | Tab | button.fchip "Projects" |  | 2px +2px | whole ring visible |
| 23 | Tab | button.fchip "Accounts" |  | 2px +2px | whole ring visible |
| 24 | Tab | select#history-project.select "Project" |  | 2px +2px | whole ring visible |
| 25 | ArrowDown | select#history-project.select "Project" | alpha chosen | 2px +2px | whole ring visible |
| 26 | Space | select#history-project.select "Project" | opens the native list | 2px +2px | whole ring visible |
| 27 | Escape | select#history-project.select "Project" | history-project keeps focus | 2px +2px | whole ring visible |
| 28 | (rows) | select#history-project.select "Project" | 64 rows shown | 2px +2px | whole ring visible |
| 29 | Tab | input#history-text.input "Filter by text" |  | 2px +0px | whole ring visible |
| 30 | Tab | div.table-scroll "History" |  | 2px +2px | covered by div.rail-in |
| 31 | Tab | body |  | none | whole ring visible |
| 32 | Tab | a.nav "Now" |  | 2px +2px | whole ring visible |
| 33 | Tab | a.nav "You" |  | 2px +2px | whole ring visible |
| 34 | Tab | a.nav "History" |  | 2px +2px | whole ring visible |
| 35 | Tab | button.nav "More" |  | 2px +2px | whole ring visible |
| 36 | Tab | a.brand-m "Intakt home" |  | 2px +2px | whole ring visible |
| 37 | Tab | button.search-trigger "Search" |  | 2px +2px | whole ring visible |
| 38 | Tab | button.icon-btn "Switch light or dark theme" |  | 2px +2px | whole ring visible |
| 39 | Tab | a "Ledger" |  | 2px +2px | whole ring visible |
| 40 | Tab | a "Trends" |  | 2px +2px | whole ring visible |
| 41 | Tab | button.fchip "Everything" |  | 2px +2px | whole ring visible |
| 42 | Tab | button.fchip "From the dashboard" |  | 2px +2px | whole ring visible |
| 43 | Space | button.fchip "From the dashboard" | aria-pressed=true, 0 rows shown | 2px +2px | whole ring visible |
| 44 | Enter | button.fchip "From the dashboard" | aria-pressed=true, 0 rows shown | 2px +2px | whole ring visible |
| 45 | Tab | button.fchip "By Intakt" |  | 2px +2px | whole ring visible |
| 46 | Tab | button.fchip "Limits" |  | 2px +2px | whole ring visible |
| 47 | Tab | button.fchip "Projects" |  | 2px +2px | whole ring visible |
| 48 | Tab | button.fchip "Accounts" |  | 2px +2px | whole ring visible |
| 49 | Tab | select#history-project.select "Project" |  | 2px +2px | whole ring visible |
| 50 | Tab | input#history-text.input "Filter by text" |  | 2px +0px | whole ring visible |
| 51 | type "sess" | input#history-text.input "sess" | 0 rows shown | 2px +0px | whole ring visible |
| 52 | Tab | div.table-scroll "History" | scrollWidth 358 client 358 | 2px +2px | whole ring visible |
| 53 | ArrowRight | div.table-scroll "History" | scrollLeft 0 | 2px +2px | whole ring visible |
| 54 | Shift+Tab | input#history-text.input "Filter by text" |  | 2px +0px | whole ring visible |
| 55 | Shift+Tab | select#history-project.select "Project" |  | 2px +2px | whole ring visible |
| 56 | Shift+Tab | button.fchip "Accounts" |  | 2px +2px | whole ring visible |
| 57 | Shift+Tab | button.fchip "Projects" |  | 2px +2px | whole ring visible |
| 58 | Shift+Tab | button.fchip "Limits" |  | 2px +2px | whole ring visible |
| 59 | Shift+Tab | button.fchip "By Intakt" |  | 2px +2px | whole ring visible |
| 60 | Shift+Tab | button.fchip "From the dashboard" |  | 2px +2px | whole ring visible |
| 61 | Shift+Tab | button.fchip "Everything" |  | 2px +2px | whole ring visible |
| 62 | Shift+Tab | a "Trends" |  | 2px +2px | whole ring visible |
| 63 | Enter | body | URL /trends | none | whole ring visible |
| 64 | Tab | a.nav "Now" |  | 2px +2px | whole ring visible |
| 65 | Tab | a.nav "You" |  | 2px +2px | whole ring visible |
| 66 | Tab | a.nav "History" |  | 2px +2px | whole ring visible |
| 67 | Tab | button.nav "More" |  | 2px +2px | whole ring visible |
| 68 | Tab | a.brand-m "Intakt home" |  | 2px +2px | whole ring visible |
| 69 | Tab | button.search-trigger "Search" |  | 2px +2px | whole ring visible |
| 70 | Tab | button.icon-btn "Switch light or dark theme" |  | 2px +2px | whole ring visible |
| 71 | Tab | a "Ledger" |  | 2px +2px | whole ring visible |
| 72 | Tab | a "Trends" |  | 2px +2px | whole ring visible |
| 73 | Tab | span.term "Passes Review passes started, whether ru" | tooltip shown | 2px +2px | whole ring visible |
| 74 | Escape | span.term "Passes" | tooltip hidden | 2px +2px | whole ring visible |
| 75 | Tab | span.term "To fix P1 and P2 findings the judge kept" | tooltip shown | 2px +2px | whole ring visible |
| 76 | Tab | span.term "One reviewer only Share of the findings " | tooltip shown | 2px +2px | whole ring visible |
| 77 | Tab | span.term "Time to verdict Median time from the sta" | tooltip shown | 2px +2px | whole ring visible |
| 78 | Tab | span.term "Cost per pass Mean of the passes with a " | tooltip shown | 2px +2px | whole ring visible |
| 79 | Tab | rect "2 P1 findings, Correctness reviewer, 0 f" |  | stroke 2px | whole ring visible |
| 80 | Tab | rect "3 P2 findings, Correctness reviewer, 3 f" |  | stroke 2px | whole ring visible |
| 81 | Tab | rect "3 P3 findings, Correctness reviewer, 3 f" |  | stroke 2px | whole ring visible |
| 82 | Tab | rect "2 P1 findings, Security reviewer, 0 foun" |  | stroke 2px | whole ring visible |
| 83 | Tab | rect "3 P2 findings, Security reviewer, 3 foun" |  | stroke 2px | whole ring visible |
| 84 | Tab | rect "3 P3 findings, Security reviewer, 3 foun" |  | stroke 2px | whole ring visible |
| 85 | Tab | rect "7 findings, Kept to fix" |  | stroke 2px | whole ring visible |
| 86 | Tab | rect "2 findings, Merged as duplicate" |  | stroke 2px | whole ring visible |
| 87 | Tab | rect "3 findings, Deferred to a follow-up issu" |  | stroke 2px | whole ring visible |
| 88 | Tab | rect "4 findings, Rejected" |  | stroke 2px | whole ring visible |
| 89 | Tab | span.term "Passes Counted by the day each pass star" | tooltip shown | 2px +2px | whole ring visible |
| 90 | Tab | rect "2 rounds, Nov 24" |  | stroke 2px | whole ring visible |
| 91 | Tab | rect "1 round, Nov 25" |  | stroke 2px | whole ring visible |
| 92 | Tab | rect "1 round, Nov 26" |  | stroke 2px | whole ring visible |
| 93 | Tab | rect "2 rounds, Nov 27" |  | stroke 2px | whole ring visible |
| 94 | Tab | rect "2 rounds, Nov 28" |  | stroke 2px | whole ring visible |
| 95 | Tab | rect "0.3150 USD, estimated, Nov 24" |  | stroke 2px | whole ring visible |
| 96 | Tab | rect "0.2160 USD, estimated, Nov 25" |  | stroke 2px | whole ring visible |
| 97 | Tab | rect "0.2850 USD, estimated, Nov 26" |  | stroke 2px | whole ring visible |
| 98 | Tab | rect "0.3015 USD, estimated, Nov 27" |  | stroke 2px | whole ring visible |
| 99 | Tab | rect "No data, Nov 28, no cost estimate" |  | stroke 2px | whole ring visible |
| 100 | Shift+Tab | rect "No data, Nov 28, no cost estimate" |  | stroke 2px | whole ring visible |
| 101 | Shift+Tab | rect "0.3015 USD, estimated, Nov 27" |  | stroke 2px | whole ring visible |
| 102 | Shift+Tab | rect "0.2850 USD, estimated, Nov 26" |  | stroke 2px | whole ring visible |
| 103 | Shift+Tab | rect "0.2160 USD, estimated, Nov 25" |  | stroke 2px | whole ring visible |
| 104 | Shift+Tab | rect "0.3150 USD, estimated, Nov 24" |  | stroke 2px | whole ring visible |
| 105 | Shift+Tab | rect "2 rounds, Nov 28" |  | stroke 2px | whole ring visible |
| 106 | Shift+Tab | rect "2 rounds, Nov 27" |  | stroke 2px | whole ring visible |
| 107 | Shift+Tab | rect "1 round, Nov 26" |  | stroke 2px | whole ring visible |
| 108 | Shift+Tab | rect "1 round, Nov 25" |  | stroke 2px | whole ring visible |
| 109 | Shift+Tab | rect "2 rounds, Nov 24" |  | stroke 2px | whole ring visible |
| 110 | Shift+Tab | span.term "Passes Counted by the day each pass star" |  | 2px +2px | whole ring visible |
| 111 | Shift+Tab | rect "4 findings, Rejected" |  | stroke 2px | whole ring visible |
| 112 | Shift+Tab | rect "3 findings, Deferred to a follow-up issu" |  | stroke 2px | whole ring visible |
| 113 | Shift+Tab | rect "2 findings, Merged as duplicate" |  | stroke 2px | whole ring visible |
| 114 | Shift+Tab | rect "7 findings, Kept to fix" |  | stroke 2px | whole ring visible |
| 115 | Shift+Tab | rect "3 P3 findings, Security reviewer, 3 foun" |  | stroke 2px | whole ring visible |
| 116 | Shift+Tab | rect "3 P2 findings, Security reviewer, 3 foun" |  | stroke 2px | whole ring visible |
| 117 | Shift+Tab | rect "2 P1 findings, Security reviewer, 0 foun" |  | stroke 2px | whole ring visible |
| 118 | Shift+Tab | rect "3 P3 findings, Correctness reviewer, 3 f" |  | stroke 2px | whole ring visible |
| 119 | Shift+Tab | rect "3 P2 findings, Correctness reviewer, 3 f" |  | stroke 2px | whole ring visible |
| 120 | Shift+Tab | rect "2 P1 findings, Correctness reviewer, 0 f" |  | stroke 2px | whole ring visible |
| 121 | Shift+Tab | span.term "Cost per pass Mean of the passes with a " |  | 2px +2px | whole ring visible |
| 122 | Shift+Tab | span.term "Time to verdict Median time from the sta" |  | 2px +2px | whole ring visible |
| 123 | Shift+Tab | span.term "One reviewer only Share of the findings " |  | 2px +2px | whole ring visible |
| 124 | Shift+Tab | span.term "To fix P1 and P2 findings the judge kept" |  | 2px +2px | whole ring visible |
| 125 | Shift+Tab | span.term "Passes Review passes started, whether ru" |  | 2px +2px | whole ring visible |
| 126 | Shift+Tab | a "Trends" |  | 2px +2px | whole ring visible |
| 127 | Shift+Tab | a "Ledger" |  | 2px +2px | whole ring visible |
| 128 | Enter | body | URL /history | none | whole ring visible |


## The exact scripts

Run as `node walk.js http://localhost:7780` (Playwright with the headless Chromium shell; both files live outside the repository, side by side; the install runs on a SQLite file with `INTAKT_CONFIG=config/intakt.example.yaml`).

### lib.js

```js
const { chromium } = require('/opt/playwright/node_modules/playwright');
exports.launch = () => chromium.launch(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {});
exports.describe = (page) => page.evaluate(() => {
  const el = document.activeElement;
  if (!el || el === document.body) return { name: 'body', ring: 'none' };
  const cs = getComputedStyle(el);
  const r = el.getBoundingClientRect();
  const svg = el instanceof SVGElement;
  const stroke = svg && cs.stroke !== 'none' ? parseFloat(cs.strokeWidth) : 0;
  const w = svg ? stroke : (cs.outlineStyle === 'none' ? 0 : parseFloat(cs.outlineWidth));
  const off = svg ? 0 : (parseFloat(cs.outlineOffset) || 0);
  const grow = svg ? stroke / 2 : w + off;
  const ring = { l: r.left - grow, t: r.top - grow, r: r.right + grow, b: r.bottom + grow };
  const issues = [];
  if (w === 0) issues.push('no visible ring');
  if (ring.l < 0 || ring.r > innerWidth) issues.push('outside viewport sideways');
  for (let a = el.parentElement; a && a !== document.documentElement; a = a.parentElement) {
    const ac = getComputedStyle(a);
    if (ac.overflowX === 'visible' && ac.overflowY === 'visible') continue;
    const b = a.getBoundingClientRect();
    const cl = b.left + a.clientLeft, cr = cl + a.clientWidth, ct = b.top + a.clientTop, cb = ct + a.clientHeight;
    const sides = [];
    if (ring.l < cl - 0.5) sides.push('left'); if (ring.r > cr + 0.5) sides.push('right');
    if (ring.t < ct - 0.5) sides.push('top'); if (ring.b > cb + 0.5) sides.push('bottom');
    if (sides.length) issues.push('clipped ' + sides.join('+') + ' by ' + a.tagName.toLowerCase() + (a.className ? '.' + String(a.className).split(' ')[0] : ''));
  }
  const x = Math.min(Math.max(r.left + r.width / 2, 1), innerWidth - 1), y = Math.min(Math.max(r.top + r.height / 2, 1), innerHeight - 1);
  const top = document.elementFromPoint(x, y);
  const hidden = r.width > 0 && !(top === el || el.contains(top) || (top && top.contains(el)));
  if (hidden) issues.push('covered by ' + (top?.tagName.toLowerCase() ?? 'nothing') + (top?.className ? '.' + String(top.className).split(' ')[0] : ''));
  const label = (el.getAttribute('aria-label') || el.innerText || el.value || el.getAttribute('placeholder') || '').trim().replace(/\s+/g, ' ').slice(0, 40);
  return { name: el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (el.className && typeof el.className === 'string' ? '.' + el.className.split(' ')[0] : '') + ' "' + label + '"', ring: svg ? 'stroke ' + w + 'px' : w + 'px +' + off + 'px', issues };
});
```

### walk.js

```js
// usage: node walk.js <address>  (keys only: Tab, Shift+Tab, Enter, Space, ArrowDown, Escape)
const { launch, describe } = require('./lib.js');
const addr = process.argv[2];
(async () => {
  const b = await launch();
  for (const [w, h] of [[1440, 900], [390, 844]]) {
    const ctx = await b.newContext({ viewport: { width: w, height: h } });
    const p = await ctx.newPage();
    const out = [];
    let n = 0;
    const note = async (key, result) => {
      const d = await describe(p);
      out.push(`| ${++n} | ${key} | ${d.name.replace(/\|/g, '/')} | ${result ?? ''} | ${d.ring} | ${(d.issues || []).length ? d.issues.join('; ') : 'whole ring visible'} |`);
    };
    const press = async (key, result) => { await p.keyboard.press(key); await note(key, typeof result === 'function' ? await result() : result); };
    const tabTo = async (match, back = false) => {
      for (let i = 0; i < 60; i++) {
        await press(back ? 'Shift+Tab' : 'Tab');
        const id = await p.evaluate(() => { const e = document.activeElement; if (e === document.body) return 'BODY'; return (e.id ? '#' + e.id : '') + ' ' + (e.getAttribute('aria-label') || e.innerText || '').trim(); });
        if (id.includes(match)) return;
      }
      throw new Error('never reached ' + match);
    };
    const rows = () => p.evaluate(() => [...document.querySelectorAll('.ledger tbody tr')].filter((r) => getComputedStyle(r).display !== 'none').length + ' rows shown');
    const state = () => p.evaluate(() => { const a = document.activeElement; return 'aria-pressed=' + a.getAttribute('aria-pressed') + ', ' + [...document.querySelectorAll('.ledger tbody tr')].filter((r) => getComputedStyle(r).display !== 'none').length + ' rows shown'; });
    await p.goto(addr + '/history', { waitUntil: 'networkidle' });
    const phone = w < 761;
    const picker = phone ? '#history-project' : '#projfilter';
    await tabTo(phone ? 'Search' : 'Search');
    await press('Enter', () => p.evaluate(() => 'dialog open: ' + !document.getElementById('search').hidden));
    await press('Escape', () => p.evaluate(() => 'palette open: ' + !document.getElementById('search').hidden));
    if (phone) { await tabTo('More', true); await press('Enter', () => p.evaluate(() => 'expanded=' + document.activeElement.getAttribute('aria-expanded'))); await press('Escape', () => p.evaluate(() => 'expanded=' + document.activeElement.getAttribute('aria-expanded'))); }
    await tabTo(picker);
    await press('ArrowDown', async () => p.evaluate(() => document.activeElement.value + ' chosen'));
    await press('Space', 'opens the native list');
    await press('Escape', async () => (await p.evaluate(() => document.activeElement.id)) + ' keeps focus');
    await note('(rows)', await rows());
    await tabTo('Everything');
    await press('Tab'); await press('Space', state);
    await press('Enter', state);
    await tabTo('#history-text');
    for (const c of 'sess') await p.keyboard.press(c);
    await note('type "sess"', await rows());
    await p.keyboard.press('Control+a'); await p.keyboard.press('Backspace');
    await press('Tab', () => p.evaluate(() => { const a = document.activeElement; a.scrollTop; return 'scrollWidth ' + a.scrollWidth + ' client ' + a.clientWidth; }));
    await press('ArrowRight', () => p.evaluate(() => 'scrollLeft ' + document.activeElement.scrollLeft));
    await tabTo('Trends', true);
    await p.keyboard.press('Enter');
    await p.waitForURL('**/trends', { waitUntil: 'commit' }); await p.waitForLoadState('networkidle');
    await note('Enter', 'URL ' + p.url().replace(addr, '') + (phone ? '' : ', top bar filter: ' + await p.evaluate(() => document.querySelector('#projfilter').value)));
    const tip = () => p.evaluate(() => { const t = document.activeElement.querySelector?.('.term-tip'); return t ? 'tooltip ' + (getComputedStyle(t).display === 'none' ? 'hidden' : 'shown') : ''; });
    let tipped = false;
    for (let i = 0; i < 40; i++) {
      await p.keyboard.press('Tab');
      const d = await describe(p);
      if (d.name === 'body') break;
      await note('Tab', await tip());
      if (!tipped && d.name.startsWith('span.term')) { tipped = true; await press('Escape', tip); }
    }
    await tabTo('Ledger', true);
    await p.keyboard.press('Enter');
    await p.waitForURL('**/history', { waitUntil: 'commit' }); await p.waitForLoadState('networkidle');
    await note('Enter', 'URL ' + p.url().replace(addr, ''));
    console.log(`## ${w} wide\n\n| # | Key | Focus lands on | Result | Ring | Whole ring visible |\n|---|---|---|---|---|---|\n${out.join('\n')}\n`);
    await ctx.close();
  }
  await b.close();
})();
```
