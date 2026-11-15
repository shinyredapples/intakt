# The journey page

`bin/journey` regenerates the numbers, tables and charts of `docs/journey.md` and draws its charts and timeline as SVG files in `docs/journey/`. The classes live in `tests/JourneyPage/` (namespace `Intakt\Tests\JourneyPage`), beside the tests that run them, like `bin/journey-alarm`; they are tooling, not application code.

## Inputs

- The GitHub API, read when the command runs: milestones, issues and pull requests of `GITHUB_REPOSITORY` (`<owner>/<name>`), each with `gh api repos/<owner>/<name>/<resource>?state=all&per_page=100 --paginate --slurp` (`GhCliApi`). `gh` takes its token from `GH_TOKEN` or `GITHUB_TOKEN`; without one the command exits 1 before any call. Pull requests are read from the pulls resource and skipped in the issues list. `GitHubReader` validates each answer (a list of pages of objects, a known state, a date that parses, an issue with a `created_at`, a closed issue with a `closed_at`) and refuses anything else with exit 1.
- `--recorded=<folder>` reads `milestones.json`, `issues.json` and `pulls.json` from a folder instead (`RecordedApi`): the same `--slurp` shape, no network and no credentials. The tests use `tests/JourneyPage/Fixtures/api/` and `tests/JourneyPage/Fixtures/api-later/`.
- `docs/journey/usage.jsonl`: JSON lines `{"day","vendor","account","lane","tokens"}`. `UsageReader` refuses a line that does not hold exactly these five fields, in any order (so a limit, a reset time or a quota never passes through), tokens that are not a whole number of zero or more, an empty text field, a `day` that is not a real `YYYY-MM-DD` date, and a vendor (`claude`, `gpt`), account (`claude-1`, `claude-2`, `claude-api`, `gpt-1`) or lane (`build`, `review`) outside the backed enums `UsageVendor`, `UsageAccount` and `UsageLane`. An account is shown only by its `UsageAccount` name (Claude account 1, Claude account 2, Claude API key, GPT subscription), never by an email or a plan.
- The page's own Mermaid block that starts with `timeline` (`MermaidTimeline`): `title`, `section` and `<period> : <event> : <event>` lines, and lines starting with `:` that add events to the period above. Events are split at a colon with spaces around it, so `18:00` stays in one event. A page without exactly one such block, a period before the first section, or a line of another form refuses the whole run.

## Outputs

Only the lines between `<!-- journey:begin NAME -->` and `<!-- journey:end NAME -->` change (`MarkedRegions`); everything else on the page stays byte for byte, the Mermaid timeline block included. A name with no marker or more than one, or an end marker before its begin, refuses the whole run and writes nothing.

- `glance`: first merged pull request, pull requests merged (to today's date from the clock), issues closed (done, and dropped for closed as not planned or as a duplicate), busiest day by merges (the earliest on a tie) and tokens per merged pull request (the usage total divided by merged pull requests). A first commit, a first release and first-pass approvals have no source among the inputs, so the table has no such rows.
- `milestones`: one row per milestone ordered by due date (none last): title, goal (its description), due date, the day of the last issue closed as done (`open` while an issue is open, `none` when no issue is open and none was closed as done), and done / open counts. Dates are `MM-DD`, with the year for any other than 2026. In a title or goal, `|` is escaped and `<` is written `&lt;`, so text from the forge can neither open a comment nor write a journey marker into the page.
- One region per chart (`ChartRegion`): the image `![<title>](journey/<name>.svg)`, then a `<details markdown="1">` block with the same numbers as a table (one row per day, `none` for a day without a value), so the chart never depends on color or sight alone.

| Region and file | Chart | Days | Series |
|---|---|---|---|
| `tokens-by-lane` | stacked bars (`StackedBarSvg`) | each day from the first to the last day in the usage file | tokens of each vendor and lane that has usage (`Claude, building`, `Claude, reviewing`, `GPT, building`, `GPT, reviewing`, in that order) |
| `tokens-by-account` | stacked bars | the same | tokens of each account that has usage, in `UsageAccount` order |
| `tokens-per-merge` | line (`LineSvg`) | the same | the day's tokens divided by the pull requests merged that day (UTC), none on a day without a merge |
| `issues-per-day` | grouped bars (`GroupedBarSvg`) | each day from the first issue created to the last issue created or closed | issues created and issues closed (done or dropped) that day |
| `open-issues` | line | the same | issues created minus issues closed up to the end of the day |

The token charts read only the usage file and the merges on its days, so with an unchanged `usage.jsonl` they stay byte for byte the same; the issue charts end on the last event, not on today, so a rerun on a later day draws the same charts.

The drawings follow the look of the charts first drawn by hand for the page (`ChartCanvas`): a 920 pixel wide surface `#fcfcfb`, four gridlines up to a round top (1, 2, 4, 5 or 10 times a power of ten, `YScale`), at most nine day labels, bars with a 4 pixel rounded top and a 2 pixel surface gap between stacked or neighboring bars, a legend for two or more series, a 2 pixel line whose last value is labelled, and `role="img"` with an `aria-label` and a `<title>`. Series colors come from the fixed order of `ChartPalette` (`#2a78d6`, `#eb6834`, `#1baf7a`, `#eda100`), given by the series' place in the chart and never cycled: a fifth series refuses the run. `ChartPaletteTest` checks that every two of these colors stay at least 8 apart in OKLab (times 100) for typical vision and for simulated protanopia, deuteranopia and tritanopia (the full-severity matrices of Machado, Oliveira and Fernandes, 2009). Coordinates are written with at most two decimals, so a rerun writes the same bytes.

`TimelineSvg` draws the Mermaid timeline as `docs/journey/timeline.svg` for GitHub Pages, whose Jekyll does not render Mermaid: the title, each section with a rule, and each period with one event per line. github.com keeps rendering the Mermaid block itself; the page does not reference `timeline.svg` yet.

The command computes everything before it writes, then writes each SVG and last `docs/journey.md`, only the files whose content changed, and prints `<path> regenerated` for each. When nothing changed it prints `docs/journey.md and its drawings are current` and writes nothing, so a rerun with the same inputs leaves every file alone. A file it cannot write exits 1 with `cannot write <path>`, before the page is written.
