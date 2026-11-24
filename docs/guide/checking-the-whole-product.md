# Checking the whole product

Every change proves itself, and reviewers read what changed. Neither shows whether Intakt as a whole still works after hundreds of changes. **Journeys** do: each one starts a fresh, empty copy of Intakt, then does what a person would do and checks what they would see. (The [journey page](the-journey-page.md), the story of how Intakt was built, is something else.)

A journey is not part of the checks every change passes (`bin/check` and `bin/check --fast` leave them out), because starting a whole copy takes longer. Run them with:

```
bin/check --journeys
```

It starts a new copy for each journey and stops everything that journey started when it ends, so one journey cannot upset the next. A passing run ends with `"result":"pass"`. A failing one names the journey and the step that broke, for example `journey "first install and setup" failed at step "open the reviews page"`, so you know where to look. A broken step fails only its own journey and names that step, and the live progress journey proves it: a second test runs that journey with the dashboard address broken on purpose and checks that it fails at the step that opens the running review, names no other step, and calls no AI.

Journeys never call an AI company or a forge and need no keys; they use the sample project only.

A third helper, the round driver, starts a review on the sample project and moves it forward one small piece of work at a time, reading the live updates after each piece until the review ends; if the review has not ended after two and a half minutes, it stops and says which review got stuck and what it last showed, before the journey's own time runs out. Two stand-ins make that possible once a journey runs a review. In the copy a journey starts, every reviewer is a pretend one that approves at once, without asking an AI. And the live updates the review page would receive are written to a file instead of being sent, so the journey can read them afterwards and check what a person watching the page would have seen. The live progress journey below uses all of this.

## What is covered

| Journey | The path | Status |
|---|---|---|
| First install and setup | a new install gets its database, its board's first line says nothing is running yet, its **Set up Intakt** button opens the first-run page, it shows an empty projects page, is given the sample project (a second time changes nothing), shows an empty reviews page, starts the first review from the first-run page, which opens that review's page, after which the button has moved to the projects page | built |
| First run of the demo without a key | a new demo install without a key opens on the first-run page, which opens on the recorded review and says in plain words that it cannot play there, above the key form | built |
| First run of the demo with a key | the same start, then a pasted Hugging Face token (a stand-in, so no provider is asked) is kept, a restart gives the sample project its reviewers, pressing the start opens the live review page of the review it started, and once the review has run that page shows the judge's verdict | built |
| A task picked, built, reviewed and merged on the demo repository | the whole life of a task | planned |
| The dashboard showing live progress | the watcher sees a pull request on the sample project (read from a recording, not a forge) and starts a review, the review page shows it in progress, every live update the review sends fits the page a person watching it holds, until the judge has ruled and the page shows it judged; the page that stayed open, with those updates applied, shows the judge's call, the problems, the tokens and the cost as a fresh load does, and no line on it asks you to reload; the first line of the board, as the dashboard sent it while a reviewer worked, names the change under review and links to its review, and once the judge has ruled the board says nothing is running; whether the browser itself listens for those updates is checked by a separate test, not by this journey | built |
| An owner decision answered | a question that waits for you, and your answer | planned |

## When a journey fails on main

A red nightly or hand-started journey run on main files one urgent task (labels `priority:urgent` and `ready`) that names each failing journey, the step that broke and the last 40 lines of what the run printed. While the journeys stay red, a later red run rewrites that same task instead of opening another; its title and labels stay as they are. A green run files nothing. If two red runs file at the same moment, the later one closes its task as a duplicate and rewrites the older one. If the output holds neither a failing journey nor a pass, or the journeys check failed without naming a journey, the command stops with a message instead of treating the run as green.

`bin/journey-alarm` does this. You can run it by hand against saved output:

```
bin/check --journeys > journeys.out
GITHUB_REPOSITORY=owner/name bin/journey-alarm --run=https://github.com/owner/name/actions/runs/1 journeys.out
```

It reads stdin when you give no file, and `--run` is optional. It talks to GitHub through `gh` and needs a token in `GH_TOKEN` (or `GITHUB_TOKEN`) in the environment: without one it stops before calling GitHub, even when `gh` is signed in, and the command itself never reads a key.

In GitHub, the journeys run on every push to main. On a pull request they run when it changes anything outside `docs/`, `tests/` and `upstream/` (the product, its app image or the CI configuration) or the journeys' own test code; a pull request that only changes docs or other tests skips them and its check still shows green. They run as the `journeys` job of the `ci` workflow. When one fails there, that pull request's checks (or that push's) turn red, and no task is filed. The `journeys` workflow also runs them on main every night. Start a run of it with its "Run workflow" button or `gh workflow run journeys.yml`. Only a red run of this workflow files or updates the urgent task. The nightly run stays for now; it is removed once the journeys in the `ci` workflow have been green on main for a week.
A run that times out files or updates a task that says so; a run you cancel files nothing.

The technical side is in [Journeys](../architecture/journeys.md).

## Journeys in a real browser

A second kind of journey check clicks through a dashboard that is already running, in a real browser without a window:

```
bin/journey-check http://localhost:7781
```

Each journey starts on a fresh page, finds buttons and text by what they say on screen, and passes or fails. A failing one names the step that broke and, when it can, saves a picture of the page in `var/journey-check/` under a folder for that run. It ends with 0 when every journey passed, 1 when one failed and 2 when it could not run, for example when nothing answers at the address. Its one journey so far, **First run without a key**, opens the start page and the demo page, plays the recorded demo run, and waits for the run's verdict and its recorded finding on the review page, so it needs a dashboard that can play the demo. It needs Node.js and Playwright: the [user manual](../reference/user-manual.md#walking-user-journeys-with-the-journey-check) has the commands, and the technical side is in [Journey check](../architecture/journey-check.md).
