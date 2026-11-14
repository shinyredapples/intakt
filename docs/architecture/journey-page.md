# The journey page

`bin/journey` regenerates the numbers and tables of `docs/journey.md`. The classes live in `tests/JourneyPage/` (namespace `Intakt\Tests\JourneyPage`), beside the tests that run them, like `bin/journey-alarm`; they are tooling, not application code.

## Inputs

- The GitHub API, read when the command runs: milestones, issues and pull requests of `GITHUB_REPOSITORY` (`<owner>/<name>`), each with `gh api repos/<owner>/<name>/<resource>?state=all&per_page=100 --paginate --slurp` (`GhCliApi`). `gh` takes its token from `GH_TOKEN` or `GITHUB_TOKEN`; without one the command exits 1 before any call. Pull requests are read from the pulls resource and skipped in the issues list. `GitHubReader` validates each answer (a list of pages of objects, a known state, a date that parses, a closed issue with a `closed_at`) and refuses anything else with exit 1.
- `--recorded=<folder>` reads `milestones.json`, `issues.json` and `pulls.json` from a folder instead (`RecordedApi`): the same `--slurp` shape, no network and no credentials. The tests use `tests/JourneyPage/Fixtures/api/`.
- `docs/journey/usage.jsonl`: JSON lines `{"day","vendor","account","lane","tokens"}`. `UsageReader` adds up `tokens` and refuses a line that does not hold exactly these five fields, in any order (so a limit, a reset time or a quota never passes through), tokens that are not a whole number of zero or more, or an empty text field. Accounts are not written to the tables.

## Outputs

Only the lines between `<!-- journey:begin NAME -->` and `<!-- journey:end NAME -->` change (`MarkedRegions`); everything else on the page stays byte for byte. A name with no marker or more than one, or an end marker before its begin, refuses the whole run and writes nothing.

- `glance`: first merged pull request, pull requests merged (to today's date from the clock), issues closed (done, and dropped for closed as not planned or as a duplicate), busiest day by merges (the earliest on a tie) and tokens per merged pull request (the usage total divided by merged pull requests). A first commit, a first release and first-pass approvals have no source among the inputs, so the table has no such rows.
- `milestones`: one row per milestone ordered by due date (none last): title, goal (its description), due date, the day of the last issue closed as done (`open` while an issue is open, `none` when no issue is open and none was closed as done), and done / open counts. Dates are `MM-DD`, with the year for any other than 2026. In a title or goal, `|` is escaped and `<` is written `&lt;`, so text from the forge can neither open a comment nor write a journey marker into the page.

The command prints `docs/journey.md regenerated` or `docs/journey.md is current` (no write when nothing changed, so a rerun with the same inputs leaves the file alone). Other regions (charts and their tables) are untouched until a later part generates them.
