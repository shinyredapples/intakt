# 8. When something goes wrong

*Chapter 8 of 9 in [the Intakt user guide](README.md).*

## A review that will not finish

It is Friday afternoon. You started a review half an hour ago, and the page still says one reviewer is running. Nothing looks broken, but nothing moves either. Most surprises in Intakt have a reason written on the screen. This chapter lists the common ones: what you see, why, and what to do.

## First, look at Waiting on you

Open **You** in the menu. Anything only you can decide shows up there, oldest first. When the page says **Nothing needs you**, the hold-up is somewhere else, and the cases below will find it.

![The Waiting on you page: the octopus above "Nothing needs you, Intakt keeps building and reviewing. When it needs a decision, it shows up here", then the sections Meanwhile, "No review is running now", and You handled today](../assets/guide/waiting-on-you.webp)

## Intakt stops at start with "not a log level"

- **What you see:** Intakt does not start, and the output says "INTAKT_LOG_LEVEL is set to a value that is not a log level: use one of emergency, alert, critical, error, warning, notice, info, debug."
- **Why:** `INTAKT_LOG_LEVEL` holds something other than one of those levels in lower case, or is empty. The message does not repeat the value, so a key set there by mistake is not printed.
- **What to do:** set `INTAKT_LOG_LEVEL` to one of the listed levels (`warning` is the usual one, `info` shows more), or remove it from `.env.local` and your environment to get `warning`, then start Intakt again.

## The setup wizard says no provider has a key

- **What you see:** step 3, **Add an AI account**, says "No provider has a key yet", with lines such as Provider "openai" is disabled: set OPENAI_API_KEY to enable it.
- **Why:** no provider has a key, so no reviewer can run.
- **What to do:** add a key as in [chapter 3](03-your-first-real-review.md), then restart Intakt.

## The setup wizard refuses your key

- **What you see:** "The provider refused the key", or "The key has the form of no listed provider".
- **Why:** the provider said no, or the text is not a whole key of a provider Intakt knows.
- **What to do:** copy the whole key again from the provider's page, and check that it is still valid.

## A reviewer reads Blocked

- **What you see:** **Blocked** beside a reviewer, with a reason such as "Every provider of the tier is out: anthropic for 300 s."
- **Why:** every account of that provider is cooling down after a rate limit, and the tier has no substitute.
- **What to do:** wait for the time it names, or add a second account or a substitute ([chapter 7](07-keys-providers-and-costs.md)).

## A reviewer reads Failed

- **What you see:** **Failed** with a reason in plain words, such as "stopped at its output limit" or "the model endpoint could not be reached".
- **Why:** the reviewer could not give a usable verdict, even after Intakt asked it once more. A failed reviewer never counts as an approval.
- **What to do:** for the output limit, raise the tier's `max_output`; for the endpoint, check your network and the provider's status. Then start the next round.

## The round ends with "no outcome in time"

- **What you see:** the review ends blocked with the reason "no outcome in time".
- **Why:** the round did not end within its upper wait. Most often no background worker was running to do the work.
  Rarely, its judging failed and was parked among more than 500 parked messages, so Intakt did not see it among the ones it reads and waited for the upper wait instead of reporting "the judging failed".
- **What to do:** the container runs its workers for you. Outside it, start one with `php bin/console messenger:consume async`, then review again.

## The cost reads "unknown"

- **What you see:** "Cost: unknown, no price for" and a model name.
- **Why:** you set no price for that model.
- **What to do:** add it under `budget.prices` ([chapter 7](07-keys-providers-and-costs.md#reading-the-cost)).

## A finding you expected is not on the list

- **What you see:** a reviewer mentioned a problem, but it is not under To fix.
- **Why:** the finding quoted code that is not in the change, so it was dropped, or the owl deferred or rejected a P3.
- **What to do:** look for its card marked **Quote not found in the code** or **Dropped**, and open **Quoted code**.

## Your agent cannot start a review

- **What you see:** an MCP error when your agent calls `start_review`.
- **Why:** the token is missing or wrong, or writes are off.
- **What to do:** on the demo, export `INTAKT_MCP_TOKEN` again ([chapter 5](05-let-your-agent-drive.md)). On your own install, set `MCP_OWNER_ACCESS=mcp_tool_write` and `MCP_OWNER_TOKEN` in `.env.local`.

## The dashboard is slow

- **What you see:** pages take seconds to load.
- **Why:** the server is busy.
- **What to do:** look at the row of server figures under the board: busy and idle threads, waiting requests, worker restarts and the hub. A figure that reads `down` says why it could not be read. Inside the container, `php bin/console intakt:server:status` shows the same.

## Docker says the container is unhealthy

- **What you see:** `docker ps` or `docker inspect` shows Intakt as `unhealthy`.
- **Why:** the health check failed three times in a row: the dashboard did not answer, the live-update hub reported a problem, no worker was ready to take a request, or the server's figures could not be read.
- **What to do:** run `docker exec intakt php bin/console intakt:server:health`. Each `unhealthy:` line names one problem and why.

## The dashboard says "Open the dashboard on localhost"

You set Intakt up on a small server in the hall and type its address into the laptop on your sofa. Instead of the board, a page tells you to open the dashboard on localhost. Nothing is broken: the dashboard has no login, so it answers only `http://localhost:7781` (7781 is the default; if you publish another port, use that port, the value of `DASHBOARD_PORT`) on the machine it runs on. Any other address could let a web page you visit act for you.

1. On the machine that runs Intakt, open `http://localhost:7781`.
2. From another device, open a tunnel first: `ssh -L 7781:localhost:7781 you@your-server`.
3. Keep that terminal open and open `http://localhost:7781` on the device.

With another published port, say 8080, use it in all three places: `http://localhost:8080` and `ssh -L 8080:localhost:8080 you@your-server`.

## A button says "Connecting to live updates…"

- **What you see:** a button stays disabled with that line below it, and after a few seconds "No live connection, so this waits."
- **Why:** the page has no live connection to Intakt, and actions wait for it.
- **What to do:** open the dashboard on `http://localhost:7781` (or your published port) as above, then reload the page.

## The demo run did not play

Before you press anything, `/demo` already says when the run cannot play: instead of **Play the demo run** it shows one sentence with the reason, such as "No demo recording is installed" or "The demo run is in the ledger", and what to do about it.

You press **Play the demo run**, the page comes back with "The demo run is starting" above the title, and then that line changes to "The demo run did not play." with a reason.

- **What you see:** that line, with a short reason such as "The demo recording could not be read.", "Another Play or a demo removal is running." or "An earlier Play has already played the run."
- **Why:** the reason says it. The details, with file names, are only in the install's log.
- **What to do:** fix what the reason names. If another Play or a removal is running, wait until it has ended, reload the page and press Play again. If an earlier Play has already played the run, follow it in the review list. If you removed the demo project with `intakt:demo:remove`, press Play again: it creates the project again. If the page still shows the completed run after a removal, reload it. A page that opened after the refusal does not show it.

## A page says "The form token is missing or wrong"

- **What you see:** after pressing a button, a page with that sentence and "Go back, reload the page and press the button again."
- **Why:** the dashboard could not match the press to the page that showed the button, for example a page opened in another browser session. Nothing happened.
- **What to do:** do what it says: go back, reload, press again.

More cases, and the exact wording of every reason, are in the [complete reference](../reference/user-manual.md).

## Trying the install the way a stranger does

`bin/install-check` runs, in a temporary folder, every command of the README's install block as written, including its `git clone` (pointed at the repository you name). Then it plays the demo run in a browser, checks every dashboard page and walks the journeys when the clone has `bin/journey-check`. A `cd` in one README line carries to the next. It never uses your own checkout, and the README's commands never see your keys or a GitHub token.

When you have set `ANTHROPIC_API_KEY` or `OPENAI_API_KEY`, it also runs one review with that key: it writes the key to the clone's `.env.local` and starts the container again, as the README's key path does.

```
bin/install-check                     # the GitHub repository's default branch
bin/install-check .                   # the repository you are in, for trying a change
```

It prints one line per step (`passed`, `failed` or `skipped`) and, under a failed step, the last lines of its log, with keys and the MCP owner token shown as `[redacted]`. It exits 0 when every step passed, 1 when a step failed (also a step that ran longer than 30 minutes, which is stopped with everything it started, or containers that would not stop) and 2 when it could not run (no `docker compose`, a clone that fails, a README without the install block) or could not report. It needs Docker, Node.js 22, Playwright and `setsid` (part of util-linux on Linux), and port 7781 free. The logs, the screenshots and `report.json` are kept in `var/install-check/<date>-<time>-<6 hex digits>/`. The containers are stopped and the clone is removed at the end, also when a step failed. Each run has its own Docker Compose project, so it never stops or removes another install's containers.

When `GITHUB_REPOSITORY=owner/name` and `GH_TOKEN` are set, the check reports itself through `bin/journey-alarm`: one urgent task per failing step, updated while the step keeps failing and closed when it passes again. An install command is known by its place in the README block, so fixing a broken command closes its task. After adding or removing a command, a task for a step the run no longer has is closed by the next run in which every install command passes. The check only updates or closes tasks that the account behind the token opened, so an issue someone else wrote with a copy of its marker is left alone. A token GitHub will not tell its own account name (a workflow's or a GitHub App's token) also needs `INSTALL_CHECK_ALARM_ACCOUNT` set to the account the issues are filed as, for example `github-actions[bot]`; without it the check files nothing and exits 2. Without `GITHUB_REPOSITORY` it only prints. A daily scheduled run is added by a later change.

Next: [Running it for a team](09-running-it-for-a-team.md)
