# Keyboard-only walk-through of History and Trends (part 3 of #3836)

The D5 flow, at 1440x900 and 390x844, on SQLite: open History, filter by project, switch to Trends, switch back. Only `Tab`, `Shift+Tab`, `Enter`, `Space`, `ArrowDown`, `ArrowRight`, `Escape` and typed letters are sent; the mouse is never used. The ledger was seeded with the throwaway script under "The exact scripts" (121 events: two projects, `sample` and `alpha`, six rounds each, with reviewers, findings and judge rows, plus a cooled-down account and a dashboard row per round and one account error from `owner`). Steps to reproduce: migrate a fresh SQLite database, run `php seed.php <database file>`, serve `public/` on port 7780 with `variables_order=EGPCS`, run `node walk.js http://localhost:7780`.

Filter by project is the top bar's `#projfilter` at 1440 and the toolbar's own `#history-project` at 390 (the phone top bar has no filter). After each key the script reads `document.activeElement` and checks that the focus ring (the outline, or the stroke of a chart bar) lies inside the viewport and inside every ancestor that clips (`overflow` not `visible`), and that nothing covers the focused control's center.

## Result

- Every control of both pages is reached with Tab in a sensible order (rail or bottom bar, search, project filter, theme toggle, Ledger/Trends sub-tabs, then the page's own controls) and Shift+Tab walks back along the same stops.
- Project filter narrows the rows, at both widths: 121 rows before the pick, 61 after ArrowDown picks `alpha` (1440 rows 16 and 20, 390 rows 29 and 33). Enter on the Trends sub-tab link keeps the chosen project (1440 row 57 shows the top bar filter on `alpha`), and the way back shows the picker still on `alpha` and 61 rows (last row of each table).
- Text filter narrows the rows with the Everything chip pressed (`aria-pressed=true`, 61 rows): typing "cooled" leaves 6 rows, and clearing the text restores 61 (1440 rows 25 and 32 to 34, 390 rows 47 and 55 to 57).
- Space presses a filter chip: on "From the dashboard" `aria-pressed` turns true and the rows drop from 61 to 7 (1440 row 40, 390 row 64). A chip is one choice of a group, so a second Space leaves it pressed (rows 41 and 65). Enter on a chip is not required by the acceptance (Space is the second key it names) and is not pressed here. Enter on a sub-tab link navigates to the other page.
- Esc: the search overlay closes and focus returns to its opener (the search button); at 390 the More sheet closes and focus returns to More; an open native select list closes with focus kept on the select (the list belongs to the browser, headless Chromium draws no list, so the step shows only that focus stays); a Trends tile tip closes and focus stays on its term; a chart bar's tip closes and focus stays on the bar.
- The ring check reads every side: the ring must lie inside the viewport (all four edges) and inside every clipping ancestor, the control's center must show the control, and the middle of each ring edge on screen must not lie under a fixed or sticky bar. The native project list is the browser's: Space is sent and only the focus is observed.
- Defects on these pages found and fixed, each with a test:
  1. 390, History toolbar: the sideways-scrolling bar left a focused chip partly outside the bar (By Intakt, Accounts, also Everything and the text input on the way back), so its ring was clipped. Before the fix the walk printed `clipped right by div.filters` for By Intakt, Accounts and the text input. Cause: the browser scrolls a partly visible control only when it is hidden entirely. Fix: `scroll-padding-inline: var(--s8)` on `.filters` and a `focusin` handler in `assets/history-filter.js` (tests `ComponentStylesTest::testOnPhonesTheFiltersBarKeepsAFocusedControlTheRingsWidthPlusOffsetAndPaddingFromItsScrollEdge`, `history-filter.test.mjs`).
  2. Trends tiles (and every other glossary term): the tip shown on focus could not be closed with Escape; before the fix the walk printed `tooltip shown` after Escape. Fix: `assets/term-dismiss.js` marks the term `data-dismissed` on Escape (focus stays), `.term[data-dismissed] .term-tip { display: none }`; the mark stays until both the focus and the pointer have left the term, so a pointer resting on it does not bring the tip back on the next Tab (tests `term-dismiss.test.mjs`, `ComponentStylesTest::testADismissedTermHidesItsTipAfterTheRuleThatShowsItOnFocusAndHover`).
  3. Trends charts: Shift+Tab up the page left a chart bar's ring under the sticky top bar at 1440 (printed `covered by header.topbar`), and at 390 the first chart bars had their ring below the viewport (printed `ring outside viewport bottom`, with the fixed bottom bar over the bottom edge). Cause: focus scrolls a control only to the nearest edge, and no scroll padding told the browser about the two bars. Fix: `html { scroll-padding-top: calc(var(--top) + var(--s8)) }` and, on phones, `scroll-padding-bottom` of the bar's height plus the safe area and a gap (tests `ComponentStylesTest::testFocusScrollsAControlClearOfTheStickyTopBar`, `testOnPhonesFocusScrollsAControlClearOfTheFixedBottomBar`). The rule sits in the shared stylesheet because the bars are the shell's; the other pages gain the same spacing.
  4. 1440, Trends "Passes per day" bars: with the seed above (charts with data) the walk printed `ring outside viewport bottom` for all six bars, forward and backward, because focus put the bar's bottom edge flush with the window's and half of its 2 px stroke fell off. Fix: `html { scroll-padding-bottom: var(--s8) }` for every width, replaced on phones by the existing bar-aware rule (test `ComponentStylesTest::testOnDesktopsFocusScrollsAControlAGapAwayFromTheWindowsBottomEdge`). After the fix the same walk prints `whole ring visible` on every Trends row at both widths.
- Not fixed, recorded:
  - 390, row 15: with the More sheet open, the walk reports the focused More button as covered by `div.menu` (the sheet's backdrop spans the bar). The ring is drawn and Esc closes the sheet and returns focus (row 16). The shell belongs to no part of History or Trends: suggested follow-up for the shell.
  - 390, rows 35, 72 and 73: the History table region (`tabindex="0"`) is as tall as the whole ledger, so its top and bottom ring edges are off screen whatever the scroll (printed `ring outside viewport bottom`). No ancestor clips it and no bar covers its visible edges. A ring around a region taller than the screen cannot be shown whole by any change to the page's own rules. At 1440 the same box passes the check.
  - After Enter on a sub-tab link the page is a new visit, so focus starts at the top of the document (the walk shows `body`), and the next Tab reaches the brand link. Turbo's normal behavior.

## Walk-through, step by step

Columns: the key, the element that holds focus afterwards, the result read from the page, the ring's size, and whether the whole ring lies inside the viewport and every clipping ancestor. The Trends chart bars (SVG rects) show a 2 px stroke as their ring. Rows named `(rows)`, `(state)`, `(clear)` and `(bar)` are readings taken without a key.

## 1440 wide

| # | Key | Focus lands on | Result | Ring | Whole ring visible |
|---|---|---|---|---|---|
| 1 | Tab | a.brand "Intakt home" |  | 2px +2px | whole ring visible |
| 2 | Tab | a.nav "Now" |  | 2px +2px | whole ring visible |
| 3 | Tab | a.nav "You" |  | 2px +2px | whole ring visible |
| 4 | Tab | a.nav "History" |  | 2px +2px | whole ring visible |
| 5 | (page) | body | URL /history | none | whole ring visible |
| 6 | Tab | a.brand "Intakt home" |  | 2px +2px | whole ring visible |
| 7 | Tab | a.nav "Now" |  | 2px +2px | whole ring visible |
| 8 | Tab | a.nav "You" |  | 2px +2px | whole ring visible |
| 9 | Tab | a.nav "History" |  | 2px +2px | whole ring visible |
| 10 | Tab | a.nav "Projects" |  | 2px +2px | whole ring visible |
| 11 | Tab | button.nav "Collapse" |  | 2px +2px | whole ring visible |
| 12 | Tab | button.search-trigger "Search tasks, pull requests, settings… C" |  | 2px +2px | whole ring visible |
| 13 | Enter | input#search-q "Search" | dialog open: true | 2px +0px | whole ring visible |
| 14 | Escape | button.search-trigger "Search tasks, pull requests, settings… C" | palette open: false | 2px +2px | whole ring visible |
| 15 | Tab | select#projfilter.select "Project" |  | 2px +2px | whole ring visible |
| 16 | (rows) | select#projfilter.select "Project" | before the project pick: 121 rows shown | 2px +2px | whole ring visible |
| 17 | ArrowDown | select#projfilter.select "Project" | alpha chosen | 2px +2px | whole ring visible |
| 18 | Space | select#projfilter.select "Project" | sent; headless Chromium draws no native list, so only the focus is observed | 2px +2px | whole ring visible |
| 19 | Escape | select#projfilter.select "Project" | projfilter keeps focus | 2px +2px | whole ring visible |
| 20 | (rows) | select#projfilter.select "Project" | after the project pick: 61 rows shown | 2px +2px | whole ring visible |
| 21 | Tab | button.icon-btn "Switch light or dark theme" |  | 2px +2px | whole ring visible |
| 22 | Tab | a "Ledger" |  | 2px +2px | whole ring visible |
| 23 | Tab | a "Trends" |  | 2px +2px | whole ring visible |
| 24 | Tab | button.fchip "Everything" |  | 2px +2px | whole ring visible |
| 25 | (state) | button.fchip "Everything" | Everything: aria-pressed=true, 61 rows shown | 2px +2px | whole ring visible |
| 26 | Tab | button.fchip "From the dashboard" |  | 2px +2px | whole ring visible |
| 27 | Tab | button.fchip "By Intakt" |  | 2px +2px | whole ring visible |
| 28 | Tab | button.fchip "Limits" |  | 2px +2px | whole ring visible |
| 29 | Tab | button.fchip "Projects" |  | 2px +2px | whole ring visible |
| 30 | Tab | button.fchip "Accounts" |  | 2px +2px | whole ring visible |
| 31 | Tab | input#history-text.input "Filter by text" |  | 2px +0px | whole ring visible |
| 32 | (rows) | input#history-text.input "Filter by text" | before typing, Everything pressed: 61 rows shown | 2px +0px | whole ring visible |
| 33 | type "cooled" | input#history-text.input "cooled" | Everything pressed, after typing: 6 rows shown | 2px +0px | whole ring visible |
| 34 | (clear) | input#history-text.input "Filter by text" | text cleared: 61 rows shown | 2px +0px | whole ring visible |
| 35 | Shift+Tab | button.fchip "Accounts" |  | 2px +2px | whole ring visible |
| 36 | Shift+Tab | button.fchip "Projects" |  | 2px +2px | whole ring visible |
| 37 | Shift+Tab | button.fchip "Limits" |  | 2px +2px | whole ring visible |
| 38 | Shift+Tab | button.fchip "By Intakt" |  | 2px +2px | whole ring visible |
| 39 | Shift+Tab | button.fchip "From the dashboard" |  | 2px +2px | whole ring visible |
| 40 | Space | button.fchip "From the dashboard" | before: see above; aria-pressed=true, 7 rows shown | 2px +2px | whole ring visible |
| 41 | Space | button.fchip "From the dashboard" | Space again: aria-pressed=true, 7 rows shown | 2px +2px | whole ring visible |
| 42 | Tab | button.fchip "By Intakt" |  | 2px +2px | whole ring visible |
| 43 | Tab | button.fchip "Limits" |  | 2px +2px | whole ring visible |
| 44 | Tab | button.fchip "Projects" |  | 2px +2px | whole ring visible |
| 45 | Tab | button.fchip "Accounts" |  | 2px +2px | whole ring visible |
| 46 | Tab | input#history-text.input "Filter by text" |  | 2px +0px | whole ring visible |
| 47 | Tab | div.table-scroll "History" | scrollWidth 1136 client 1136 | 2px +2px | whole ring visible |
| 48 | ArrowRight | div.table-scroll "History" | scrollLeft 0 | 2px +2px | whole ring visible |
| 49 | Shift+Tab | input#history-text.input "Filter by text" |  | 2px +0px | whole ring visible |
| 50 | Shift+Tab | button.fchip "Accounts" |  | 2px +2px | whole ring visible |
| 51 | Shift+Tab | button.fchip "Projects" |  | 2px +2px | whole ring visible |
| 52 | Shift+Tab | button.fchip "Limits" |  | 2px +2px | whole ring visible |
| 53 | Shift+Tab | button.fchip "By Intakt" |  | 2px +2px | whole ring visible |
| 54 | Shift+Tab | button.fchip "From the dashboard" |  | 2px +2px | whole ring visible |
| 55 | Shift+Tab | button.fchip "Everything" |  | 2px +2px | whole ring visible |
| 56 | Shift+Tab | a "Trends" |  | 2px +2px | whole ring visible |
| 57 | Enter | body | URL /trends, top bar filter: alpha | none | whole ring visible |
| 58 | Tab | a.brand "Intakt home" |  | 2px +2px | whole ring visible |
| 59 | Tab | a.nav "Now" |  | 2px +2px | whole ring visible |
| 60 | Tab | a.nav "You" |  | 2px +2px | whole ring visible |
| 61 | Tab | a.nav "History" |  | 2px +2px | whole ring visible |
| 62 | Tab | a.nav "Projects" |  | 2px +2px | whole ring visible |
| 63 | Tab | button.nav "Collapse" |  | 2px +2px | whole ring visible |
| 64 | Tab | button.search-trigger "Search tasks, pull requests, settings… C" |  | 2px +2px | whole ring visible |
| 65 | Tab | select#projfilter.select "Project" |  | 2px +2px | whole ring visible |
| 66 | Tab | button.icon-btn "Switch light or dark theme" |  | 2px +2px | whole ring visible |
| 67 | Tab | a "Ledger" |  | 2px +2px | whole ring visible |
| 68 | Tab | a "Trends" |  | 2px +2px | whole ring visible |
| 69 | Tab | span.term "Passes Review passes started, whether ru" | tooltip shown | 2px +2px | whole ring visible |
| 70 | Escape | span.term "Passes" | tooltip hidden | 2px +2px | whole ring visible |
| 71 | Tab | span.term "To fix P1 and P2 findings the judge kept" | tooltip shown | 2px +2px | whole ring visible |
| 72 | Tab | span.term "One reviewer only Share of the findings " | tooltip shown | 2px +2px | whole ring visible |
| 73 | Tab | span.term "Time to verdict Median time from the sta" | tooltip shown | 2px +2px | whole ring visible |
| 74 | Tab | rect "12 P1 findings, Correctness reviewer, 0 " |  | stroke 2px | whole ring visible |
| 75 | (bar) | rect "12 P1 findings, Correctness reviewer, 0 " | chart tip shown | stroke 2px | whole ring visible |
| 76 | Escape | rect "12 P1 findings, Correctness reviewer, 0 " | chart tip hidden, focus kept on rect | stroke 2px | whole ring visible |
| 77 | Tab | rect "12 P2 findings, Correctness reviewer, 0 " |  | stroke 2px | whole ring visible |
| 78 | Tab | rect "0 P3 findings, Correctness reviewer, 0 f" |  | stroke 2px | whole ring visible |
| 79 | Tab | rect "12 P1 findings, Security reviewer, 0 fou" |  | stroke 2px | whole ring visible |
| 80 | Tab | rect "12 P2 findings, Security reviewer, 0 fou" |  | stroke 2px | whole ring visible |
| 81 | Tab | rect "0 P3 findings, Security reviewer, 0 foun" |  | stroke 2px | whole ring visible |
| 82 | Tab | span.term "Passes Counted by the day each pass star" | tooltip shown | 2px +2px | whole ring visible |
| 83 | Tab | rect "2 rounds, Nov 25" |  | stroke 2px | whole ring visible |
| 84 | Tab | rect "2 rounds, Nov 26" |  | stroke 2px | whole ring visible |
| 85 | Tab | rect "3 rounds, Nov 27" |  | stroke 2px | whole ring visible |
| 86 | Tab | rect "2 rounds, Nov 28" |  | stroke 2px | whole ring visible |
| 87 | Tab | rect "2 rounds, Nov 29" |  | stroke 2px | whole ring visible |
| 88 | Tab | rect "1 round, Nov 30" |  | stroke 2px | whole ring visible |
| 89 | Shift+Tab | rect "1 round, Nov 30" |  | stroke 2px | whole ring visible |
| 90 | Shift+Tab | rect "2 rounds, Nov 29" |  | stroke 2px | whole ring visible |
| 91 | Shift+Tab | rect "2 rounds, Nov 28" |  | stroke 2px | whole ring visible |
| 92 | Shift+Tab | rect "3 rounds, Nov 27" |  | stroke 2px | whole ring visible |
| 93 | Shift+Tab | rect "2 rounds, Nov 26" |  | stroke 2px | whole ring visible |
| 94 | Shift+Tab | rect "2 rounds, Nov 25" |  | stroke 2px | whole ring visible |
| 95 | Shift+Tab | span.term "Passes Counted by the day each pass star" |  | 2px +2px | whole ring visible |
| 96 | Shift+Tab | rect "0 P3 findings, Security reviewer, 0 foun" |  | stroke 2px | whole ring visible |
| 97 | Shift+Tab | rect "12 P2 findings, Security reviewer, 0 fou" |  | stroke 2px | whole ring visible |
| 98 | Shift+Tab | rect "12 P1 findings, Security reviewer, 0 fou" |  | stroke 2px | whole ring visible |
| 99 | Shift+Tab | rect "0 P3 findings, Correctness reviewer, 0 f" |  | stroke 2px | whole ring visible |
| 100 | Shift+Tab | rect "12 P2 findings, Correctness reviewer, 0 " |  | stroke 2px | whole ring visible |
| 101 | Shift+Tab | rect "12 P1 findings, Correctness reviewer, 0 " |  | stroke 2px | whole ring visible |
| 102 | Shift+Tab | span.term "Time to verdict Median time from the sta" |  | 2px +2px | whole ring visible |
| 103 | Shift+Tab | span.term "One reviewer only Share of the findings " |  | 2px +2px | whole ring visible |
| 104 | Shift+Tab | span.term "To fix P1 and P2 findings the judge kept" |  | 2px +2px | whole ring visible |
| 105 | Shift+Tab | span.term "Passes Review passes started, whether ru" |  | 2px +2px | whole ring visible |
| 106 | Shift+Tab | a "Trends" |  | 2px +2px | whole ring visible |
| 107 | Shift+Tab | a "Ledger" |  | 2px +2px | whole ring visible |
| 108 | Enter | body | URL /history, project picker alpha, 61 rows shown | none | whole ring visible |

## 390 wide

| # | Key | Focus lands on | Result | Ring | Whole ring visible |
|---|---|---|---|---|---|
| 1 | Tab | a.nav "Now" |  | 2px +2px | whole ring visible |
| 2 | Tab | a.nav "You" |  | 2px +2px | whole ring visible |
| 3 | Tab | a.nav "History" |  | 2px +2px | whole ring visible |
| 4 | (page) | body | URL /history | none | whole ring visible |
| 5 | Tab | a.nav "Now" |  | 2px +2px | whole ring visible |
| 6 | Tab | a.nav "You" |  | 2px +2px | whole ring visible |
| 7 | Tab | a.nav "History" |  | 2px +2px | whole ring visible |
| 8 | Tab | button.nav "More" |  | 2px +2px | whole ring visible |
| 9 | Tab | a.brand-m "Intakt home" |  | 2px +2px | whole ring visible |
| 10 | Tab | button.search-trigger "Search" |  | 2px +2px | whole ring visible |
| 11 | Enter | input#search-q "Search" | dialog open: true | 2px +0px | whole ring visible |
| 12 | Escape | button.search-trigger "Search" | palette open: false | 2px +2px | whole ring visible |
| 13 | Shift+Tab | a.brand-m "Intakt home" |  | 2px +2px | whole ring visible |
| 14 | Shift+Tab | button.nav "More" |  | 2px +2px | whole ring visible |
| 15 | Enter | button.nav "More" | expanded=true | 2px +2px | covered by div.menu |
| 16 | Escape | button.nav "More" | expanded=false | 2px +2px | whole ring visible |
| 17 | Tab | a.brand-m "Intakt home" |  | 2px +2px | whole ring visible |
| 18 | Tab | button.search-trigger "Search" |  | 2px +2px | whole ring visible |
| 19 | Tab | button.icon-btn "Switch light or dark theme" |  | 2px +2px | whole ring visible |
| 20 | Tab | a "Ledger" |  | 2px +2px | whole ring visible |
| 21 | Tab | a "Trends" |  | 2px +2px | whole ring visible |
| 22 | Tab | button.fchip "Everything" |  | 2px +2px | whole ring visible |
| 23 | Tab | button.fchip "From the dashboard" |  | 2px +2px | whole ring visible |
| 24 | Tab | button.fchip "By Intakt" |  | 2px +2px | whole ring visible |
| 25 | Tab | button.fchip "Limits" |  | 2px +2px | whole ring visible |
| 26 | Tab | button.fchip "Projects" |  | 2px +2px | whole ring visible |
| 27 | Tab | button.fchip "Accounts" |  | 2px +2px | whole ring visible |
| 28 | Tab | select#history-project.select "Project" |  | 2px +2px | whole ring visible |
| 29 | (rows) | select#history-project.select "Project" | before the project pick: 121 rows shown | 2px +2px | whole ring visible |
| 30 | ArrowDown | select#history-project.select "Project" | alpha chosen | 2px +2px | whole ring visible |
| 31 | Space | select#history-project.select "Project" | sent; headless Chromium draws no native list, so only the focus is observed | 2px +2px | whole ring visible |
| 32 | Escape | select#history-project.select "Project" | history-project keeps focus | 2px +2px | whole ring visible |
| 33 | (rows) | select#history-project.select "Project" | after the project pick: 61 rows shown | 2px +2px | whole ring visible |
| 34 | Tab | input#history-text.input "Filter by text" |  | 2px +0px | whole ring visible |
| 35 | Tab | div.table-scroll "History" |  | 2px +2px | ring outside viewport bottom |
| 36 | Tab | body |  | none | whole ring visible |
| 37 | Tab | a.nav "Now" |  | 2px +2px | whole ring visible |
| 38 | Tab | a.nav "You" |  | 2px +2px | whole ring visible |
| 39 | Tab | a.nav "History" |  | 2px +2px | whole ring visible |
| 40 | Tab | button.nav "More" |  | 2px +2px | whole ring visible |
| 41 | Tab | a.brand-m "Intakt home" |  | 2px +2px | whole ring visible |
| 42 | Tab | button.search-trigger "Search" |  | 2px +2px | whole ring visible |
| 43 | Tab | button.icon-btn "Switch light or dark theme" |  | 2px +2px | whole ring visible |
| 44 | Tab | a "Ledger" |  | 2px +2px | whole ring visible |
| 45 | Tab | a "Trends" |  | 2px +2px | whole ring visible |
| 46 | Tab | button.fchip "Everything" |  | 2px +2px | whole ring visible |
| 47 | (state) | button.fchip "Everything" | Everything: aria-pressed=true, 61 rows shown | 2px +2px | whole ring visible |
| 48 | Tab | button.fchip "From the dashboard" |  | 2px +2px | whole ring visible |
| 49 | Tab | button.fchip "By Intakt" |  | 2px +2px | whole ring visible |
| 50 | Tab | button.fchip "Limits" |  | 2px +2px | whole ring visible |
| 51 | Tab | button.fchip "Projects" |  | 2px +2px | whole ring visible |
| 52 | Tab | button.fchip "Accounts" |  | 2px +2px | whole ring visible |
| 53 | Tab | select#history-project.select "Project" |  | 2px +2px | whole ring visible |
| 54 | Tab | input#history-text.input "Filter by text" |  | 2px +0px | whole ring visible |
| 55 | (rows) | input#history-text.input "Filter by text" | before typing, Everything pressed: 61 rows shown | 2px +0px | whole ring visible |
| 56 | type "cooled" | input#history-text.input "cooled" | Everything pressed, after typing: 6 rows shown | 2px +0px | whole ring visible |
| 57 | (clear) | input#history-text.input "Filter by text" | text cleared: 61 rows shown | 2px +0px | whole ring visible |
| 58 | Shift+Tab | select#history-project.select "Project" |  | 2px +2px | whole ring visible |
| 59 | Shift+Tab | button.fchip "Accounts" |  | 2px +2px | whole ring visible |
| 60 | Shift+Tab | button.fchip "Projects" |  | 2px +2px | whole ring visible |
| 61 | Shift+Tab | button.fchip "Limits" |  | 2px +2px | whole ring visible |
| 62 | Shift+Tab | button.fchip "By Intakt" |  | 2px +2px | whole ring visible |
| 63 | Shift+Tab | button.fchip "From the dashboard" |  | 2px +2px | whole ring visible |
| 64 | Space | button.fchip "From the dashboard" | before: see above; aria-pressed=true, 7 rows shown | 2px +2px | whole ring visible |
| 65 | Space | button.fchip "From the dashboard" | Space again: aria-pressed=true, 7 rows shown | 2px +2px | whole ring visible |
| 66 | Tab | button.fchip "By Intakt" |  | 2px +2px | whole ring visible |
| 67 | Tab | button.fchip "Limits" |  | 2px +2px | whole ring visible |
| 68 | Tab | button.fchip "Projects" |  | 2px +2px | whole ring visible |
| 69 | Tab | button.fchip "Accounts" |  | 2px +2px | whole ring visible |
| 70 | Tab | select#history-project.select "Project" |  | 2px +2px | whole ring visible |
| 71 | Tab | input#history-text.input "Filter by text" |  | 2px +0px | whole ring visible |
| 72 | Tab | div.table-scroll "History" | scrollWidth 358 client 358 | 2px +2px | ring outside viewport bottom |
| 73 | ArrowRight | div.table-scroll "History" | scrollLeft 0 | 2px +2px | ring outside viewport bottom |
| 74 | Shift+Tab | input#history-text.input "Filter by text" |  | 2px +0px | whole ring visible |
| 75 | Shift+Tab | select#history-project.select "Project" |  | 2px +2px | whole ring visible |
| 76 | Shift+Tab | button.fchip "Accounts" |  | 2px +2px | whole ring visible |
| 77 | Shift+Tab | button.fchip "Projects" |  | 2px +2px | whole ring visible |
| 78 | Shift+Tab | button.fchip "Limits" |  | 2px +2px | whole ring visible |
| 79 | Shift+Tab | button.fchip "By Intakt" |  | 2px +2px | whole ring visible |
| 80 | Shift+Tab | button.fchip "From the dashboard" |  | 2px +2px | whole ring visible |
| 81 | Shift+Tab | button.fchip "Everything" |  | 2px +2px | whole ring visible |
| 82 | Shift+Tab | a "Trends" |  | 2px +2px | whole ring visible |
| 83 | Enter | body | URL /trends | none | whole ring visible |
| 84 | Tab | a.nav "Now" |  | 2px +2px | whole ring visible |
| 85 | Tab | a.nav "You" |  | 2px +2px | whole ring visible |
| 86 | Tab | a.nav "History" |  | 2px +2px | whole ring visible |
| 87 | Tab | button.nav "More" |  | 2px +2px | whole ring visible |
| 88 | Tab | a.brand-m "Intakt home" |  | 2px +2px | whole ring visible |
| 89 | Tab | button.search-trigger "Search" |  | 2px +2px | whole ring visible |
| 90 | Tab | button.icon-btn "Switch light or dark theme" |  | 2px +2px | whole ring visible |
| 91 | Tab | a "Ledger" |  | 2px +2px | whole ring visible |
| 92 | Tab | a "Trends" |  | 2px +2px | whole ring visible |
| 93 | Tab | span.term "Passes Review passes started, whether ru" | tooltip shown | 2px +2px | whole ring visible |
| 94 | Escape | span.term "Passes" | tooltip hidden | 2px +2px | whole ring visible |
| 95 | Tab | span.term "To fix P1 and P2 findings the judge kept" | tooltip shown | 2px +2px | whole ring visible |
| 96 | Tab | span.term "One reviewer only Share of the findings " | tooltip shown | 2px +2px | whole ring visible |
| 97 | Tab | span.term "Time to verdict Median time from the sta" | tooltip shown | 2px +2px | whole ring visible |
| 98 | Tab | rect "12 P1 findings, Correctness reviewer, 0 " |  | stroke 2px | whole ring visible |
| 99 | (bar) | rect "12 P1 findings, Correctness reviewer, 0 " | chart tip shown | stroke 2px | whole ring visible |
| 100 | Escape | rect "12 P1 findings, Correctness reviewer, 0 " | chart tip hidden, focus kept on rect | stroke 2px | whole ring visible |
| 101 | Tab | rect "12 P2 findings, Correctness reviewer, 0 " |  | stroke 2px | whole ring visible |
| 102 | Tab | rect "0 P3 findings, Correctness reviewer, 0 f" |  | stroke 2px | whole ring visible |
| 103 | Tab | rect "12 P1 findings, Security reviewer, 0 fou" |  | stroke 2px | whole ring visible |
| 104 | Tab | rect "12 P2 findings, Security reviewer, 0 fou" |  | stroke 2px | whole ring visible |
| 105 | Tab | rect "0 P3 findings, Security reviewer, 0 foun" |  | stroke 2px | whole ring visible |
| 106 | Tab | span.term "Passes Counted by the day each pass star" | tooltip shown | 2px +2px | whole ring visible |
| 107 | Tab | rect "2 rounds, Nov 25" |  | stroke 2px | whole ring visible |
| 108 | Tab | rect "2 rounds, Nov 26" |  | stroke 2px | whole ring visible |
| 109 | Tab | rect "3 rounds, Nov 27" |  | stroke 2px | whole ring visible |
| 110 | Tab | rect "2 rounds, Nov 28" |  | stroke 2px | whole ring visible |
| 111 | Tab | rect "2 rounds, Nov 29" |  | stroke 2px | whole ring visible |
| 112 | Tab | rect "1 round, Nov 30" |  | stroke 2px | whole ring visible |
| 113 | Shift+Tab | rect "1 round, Nov 30" |  | stroke 2px | whole ring visible |
| 114 | Shift+Tab | rect "2 rounds, Nov 29" |  | stroke 2px | whole ring visible |
| 115 | Shift+Tab | rect "2 rounds, Nov 28" |  | stroke 2px | whole ring visible |
| 116 | Shift+Tab | rect "3 rounds, Nov 27" |  | stroke 2px | whole ring visible |
| 117 | Shift+Tab | rect "2 rounds, Nov 26" |  | stroke 2px | whole ring visible |
| 118 | Shift+Tab | rect "2 rounds, Nov 25" |  | stroke 2px | whole ring visible |
| 119 | Shift+Tab | span.term "Passes Counted by the day each pass star" |  | 2px +2px | whole ring visible |
| 120 | Shift+Tab | rect "0 P3 findings, Security reviewer, 0 foun" |  | stroke 2px | whole ring visible |
| 121 | Shift+Tab | rect "12 P2 findings, Security reviewer, 0 fou" |  | stroke 2px | whole ring visible |
| 122 | Shift+Tab | rect "12 P1 findings, Security reviewer, 0 fou" |  | stroke 2px | whole ring visible |
| 123 | Shift+Tab | rect "0 P3 findings, Correctness reviewer, 0 f" |  | stroke 2px | whole ring visible |
| 124 | Shift+Tab | rect "12 P2 findings, Correctness reviewer, 0 " |  | stroke 2px | whole ring visible |
| 125 | Shift+Tab | rect "12 P1 findings, Correctness reviewer, 0 " |  | stroke 2px | whole ring visible |
| 126 | Shift+Tab | span.term "Time to verdict Median time from the sta" |  | 2px +2px | whole ring visible |
| 127 | Shift+Tab | span.term "One reviewer only Share of the findings " |  | 2px +2px | whole ring visible |
| 128 | Shift+Tab | span.term "To fix P1 and P2 findings the judge kept" |  | 2px +2px | whole ring visible |
| 129 | Shift+Tab | span.term "Passes Review passes started, whether ru" |  | 2px +2px | whole ring visible |
| 130 | Shift+Tab | a "Trends" |  | 2px +2px | whole ring visible |
| 131 | Shift+Tab | a "Ledger" |  | 2px +2px | whole ring visible |
| 132 | Enter | body | URL /history, project picker alpha, 61 rows shown | none | whole ring visible |


## The exact scripts

Run as `node walk.js http://localhost:7780` (Playwright with the headless Chromium shell; the files live outside the repository, side by side; the install runs on a SQLite file with `INTAKT_CONFIG=config/intakt.example.yaml`).

### seed.php

```php
<?php
// throwaway seed: php seed.php <sqlite file>
$d = new PDO('sqlite:'.$argv[1]);
$s = $d->prepare('INSERT INTO ledger_event (id, kind, payload, recorded_at, subject) VALUES (?,?,?,?,?)');
$t = strtotime('-3 days'); $n = 0;
$add = function (string $kind, array $p, ?string $subject = null) use ($s, &$t, &$n) { $t += 3600; $s->execute([random_bytes(16), $kind, json_encode($p), gmdate('Y-m-d H:i:s', $t), $subject]); $n++; };
foreach (['sample', 'alpha'] as $proj) {
    for ($i = 1; $i <= 6; $i++) {
        $r = "$proj-r$i";
        $add('review_started', ['project' => $proj, 'round' => $r, 'reviewers' => 2]);
        foreach (['correctness', 'security'] as $role) {
            $rv = ['round' => $r, 'reviewer' => "$r-$role", 'role' => $role, 'tier' => 'standard'];
            $add('reviewer_started', $rv, $r);
            $add('verdict_received', $rv + ['decision' => 'changes', 'findings' => 2]);
            $add('reviewer_findings_recorded', ['round' => $r, 'reviewer' => "$r-$role", 'findings' => ['F1', 'F2'], 'severities' => ['F1' => 'P1', 'F2' => 'P2']]);
        }
        $add('judge_done', ['round' => $r, 'act' => ['F1'], 'follow_ups' => [], 'findings' => [['id' => 'F1', 'severity' => 'P1', 'text' => 'A finding.', 'merged' => []], ['id' => 'F2', 'severity' => 'P2', 'text' => 'Another.', 'merged' => []]]]);
        $add('reviewer_stop_requested', ['project' => $proj, 'round' => $r]);
        $add('account_cooled_down', ['project' => $proj, 'round' => $r, 'account' => "session-$i"]);
    }
}
$add('account_error_reported', ['account' => 'session-9', 'actor' => 'owner']);
echo "$n events\n";
```

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
  const off_ = [];
  if (ring.l < 0) off_.push('left'); if (ring.r > innerWidth) off_.push('right'); if (ring.t < 0) off_.push('top'); if (ring.b > innerHeight) off_.push('bottom');
  if (off_.length) issues.push('ring outside viewport ' + off_.join('+'));
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
  // Samples the control's center and the middle of each ring edge that lies on screen; a point of the ring under another element counts as covered.
  const pts = [[r.left + r.width / 2, r.top + r.height / 2], [ring.l, r.top + r.height / 2], [ring.r, r.top + r.height / 2], [r.left + r.width / 2, ring.t], [r.left + r.width / 2, ring.b]]
    .filter(([px, py]) => px >= 0 && px <= innerWidth - 1 && py >= 0 && py <= innerHeight - 1);
  const fixedAbove = (n) => { for (let a = n; a && a !== document.documentElement; a = a.parentElement) { const pos = getComputedStyle(a).position; if (pos === 'fixed' || pos === 'sticky') return a; } return null; };
  const coverers = new Set();
  pts.forEach(([px, py], i) => {
    const top = document.elementFromPoint(px, py);
    const own = top === el || el.contains(top) || (top && top.contains(el));
    // The center must show the control itself; a ring point may sit on a neighbor (an outline paints over it) but not under a fixed or sticky bar.
    const bar = top && fixedAbove(top);
    const covered = i === 0 ? !own : (!own && bar && !bar.contains(el));
    if (r.width > 0 && covered) coverers.add((top?.tagName.toLowerCase() ?? 'nothing') + (top?.className && typeof top.className === 'string' ? '.' + top.className.split(' ')[0] : ''));
  });
  if (pts.length === 0) issues.push('no point of the control on screen');
  if (coverers.size) issues.push('covered by ' + [...coverers].join(', '));
  const label = (el.getAttribute('aria-label') || el.innerText || el.value || el.getAttribute('placeholder') || '').trim().replace(/\s+/g, ' ').slice(0, 40);
  return { name: el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (el.className && typeof el.className === 'string' ? '.' + el.className.split(' ')[0] : '') + ' "' + label + '"', ring: svg ? 'stroke ' + w + 'px' : w + 'px +' + off + 'px', issues };
});
```

### walk.js

```js
// usage: node walk.js <address>  (keys only: Tab, Shift+Tab, Enter, Space, ArrowDown, ArrowRight, Escape, typed letters)
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
    await p.goto(addr + '/', { waitUntil: 'networkidle' });
    const phone = w < 761;
    const picker = phone ? '#history-project' : '#projfilter';
    await tabTo('History');
    await p.keyboard.press('Enter');
    await p.waitForURL('**/history', { waitUntil: 'commit' }); await p.waitForLoadState('networkidle');
    await note('(page)', 'URL ' + p.url().replace(addr, ''));
    await tabTo('Search');
    await press('Enter', () => p.evaluate(() => 'dialog open: ' + !document.getElementById('search').hidden));
    await press('Escape', () => p.evaluate(() => 'palette open: ' + !document.getElementById('search').hidden));
    if (phone) { await tabTo('More', true); await press('Enter', () => p.evaluate(() => 'expanded=' + document.activeElement.getAttribute('aria-expanded'))); await press('Escape', () => p.evaluate(() => 'expanded=' + document.activeElement.getAttribute('aria-expanded'))); }
    await tabTo(picker);
    await note('(rows)', 'before the project pick: ' + await rows());
    await press('ArrowDown', async () => p.evaluate(() => document.activeElement.value + ' chosen'));
    await press('Space', 'sent; headless Chromium draws no native list, so only the focus is observed');
    await press('Escape', async () => (await p.evaluate(() => document.activeElement.id)) + ' keeps focus');
    await note('(rows)', 'after the project pick: ' + await rows());
    await tabTo('Everything');
    await note('(state)', 'Everything: ' + await state());
    await tabTo('#history-text');
    await note('(rows)', 'before typing, Everything pressed: ' + await rows());
    for (const c of 'cooled') await p.keyboard.press(c);
    await note('type "cooled"', 'Everything pressed, after typing: ' + await rows());
    await p.keyboard.press('Control+a'); await p.keyboard.press('Backspace');
    await note('(clear)', 'text cleared: ' + await rows());
    await tabTo('From the dashboard', true);
    await press('Space', async () => 'before: see above; ' + await state());
    await press('Space', async () => 'Space again: ' + await state());
    await tabTo('#history-text');
    await press('Tab', () => p.evaluate(() => { const a = document.activeElement; a.scrollTop; return 'scrollWidth ' + a.scrollWidth + ' client ' + a.clientWidth; }));
    await press('ArrowRight', () => p.evaluate(() => 'scrollLeft ' + document.activeElement.scrollLeft));
    await tabTo('Trends', true);
    await p.keyboard.press('Enter');
    await p.waitForURL('**/trends', { waitUntil: 'commit' }); await p.waitForLoadState('networkidle');
    await note('Enter', 'URL ' + p.url().replace(addr, '') + (phone ? '' : ', top bar filter: ' + await p.evaluate(() => document.querySelector('#projfilter').value)));
    const tip = () => p.evaluate(() => { const t = document.activeElement.querySelector?.('.term-tip'); return t ? 'tooltip ' + (getComputedStyle(t).display === 'none' ? 'hidden' : 'shown') : ''; });
    let tipped = false, barTipped = false;
    const chartTip = () => p.evaluate(() => { const t = document.querySelector('[data-chart-target=tip]'); return t ? 'chart tip ' + (t.hidden ? 'hidden' : 'shown') : 'no chart tip'; });
    for (let i = 0; i < 150; i++) {
      await p.keyboard.press('Tab');
      const d = await describe(p);
      if (d.name === 'body') break;
      await note('Tab', await tip());
      if (!tipped && d.name.startsWith('span.term')) { tipped = true; await press('Escape', tip); }
      if (!barTipped && d.name.startsWith('rect')) { barTipped = true; await note('(bar)', await chartTip()); await press('Escape', async () => (await chartTip()) + ', focus kept on ' + await p.evaluate(() => document.activeElement.tagName.toLowerCase())); }
    }
    await tabTo('Ledger', true);
    await p.keyboard.press('Enter');
    await p.waitForURL('**/history', { waitUntil: 'commit' }); await p.waitForLoadState('networkidle');
    await note('Enter', 'URL ' + p.url().replace(addr, '') + ', ' + await p.evaluate((sel) => 'project picker ' + document.querySelector(sel)?.value, picker) + ', ' + await rows());
    console.log(`## ${w} wide\n\n| # | Key | Focus lands on | Result | Ring | Whole ring visible |\n|---|---|---|---|---|---|\n${out.join('\n')}\n`);
    await ctx.close();
  }
  await b.close();
})();
```
