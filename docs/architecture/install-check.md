# The install check

## In short

`bin/install-check [<repository address or path>]` installs the project the way a stranger does, from the README on a clean clone, and reports the first thing that breaks. Hundreds of tests had passed while a fresh install could not save its API key; only a real install found it.

## How it works

- **The clone.** `git clone` of the address (default `https://github.com/shinyredapples/intakt.git`) into a new folder under the system temporary folder. The current checkout is never used. An address that is empty or starts with a dash is refused.
- **The commands.** `ReadmeInstallBlock` reads them from the README: the install block is the first code fence after the line that starts with `<!-- install-check`. The README stays the one source. A `git clone` line is skipped, because the clone step already did that; every other line runs verbatim in the clone, one step each (`install: <command>`). A README edit that breaks a command fails that step. A missing marker, a marker not followed by a code block or an unclosed block means the check could not run.
- **The walk.** After the install commands: `demo` (`bin/install-check-demo`: open `/demo`, press **Play the demo run**, wait for `#review-verdict`; no model is called), `ui-check` (`bin/ui-check` over every page), `journey-check` (`bin/journey-check` when the clone has it, otherwise skipped) and `keyed`. A failed install command skips the later install commands and every step after them.
- **The keyed step** runs `docker compose exec -T -e <NAME> intakt php bin/console intakt:demo` and runs only when `ANTHROPIC_API_KEY` or `OPENAI_API_KEY` is set (the first one found). No other variable is read. The key reaches docker by name, so it is on no command line; its value is replaced by `[redacted]` in every log and in the report.
- **The end.** `InstallCheck::run()` stops the containers (`docker compose down -v --remove-orphans`) and removes the clone in a `finally`, so a failing step or a check that could not run leaves neither behind.

## Output and exit codes

One line per step, `passed`, `failed` or `skipped`, then the last 40 lines of a failed step's log. Logs, the demo screenshot, the copied `bin/ui-check` screenshots and `report.json` go to `var/install-check/<date>-<time>/`, one folder a workflow can upload. Exit 0: every step passed or was skipped. Exit 1: a step failed. Exit 2: the check could not run (`InstallCheckCouldNotRun`: no `docker compose`, the clone failed, the README block is missing).

## Reporting

`bin/journey-alarm --install-check=<report.json>` reads `report.json` (validated by `InstallReport::fromJson()`) and hands each step to `InstallCheckAlarm`, which uses the same `GitHubIssues` and lock as the journeys alarm. A failed step files one urgent, ready task or rewrites the open one; the marker `<!-- intakt:install-check:<hash of the step name> -->` ties the task to the step. A step that passes closes its open task; a skipped step changes nothing. A daily scheduled run is added by a later change.
