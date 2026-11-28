# How Intakt uses Symfony, in plain words

This page explains which parts of Symfony and Symfony AI Intakt is built from, and what each part does for it, without assuming you know Symfony. The exact list, with the status of every feature and where it lives in the code, is in the [highlights page](https://shinyredapples.github.io/intakt/highlights/), built by `bin/highlights` from [`docs/highlights.d/`](../highlights.d/) when its inputs change, daily and by hand, not committed. When it is published, a feature's status becomes done once every issue and pull request it names is closed, and stays in progress while one is open. Intakt is still being built: each section says what is **in use** already and what is **planned**.

## First, what are these?

- **Symfony** is a toolbox for building PHP applications. Instead of writing everything from scratch, you pick ready-made, well-tested parts (the "components"): one for running jobs in the background, one for timers, one for checking data, and so on.
- **Symfony AI** is a newer set of parts for working with AI models: talking to models from different companies through one interface, giving them tools, and giving them a memory. It is still in beta, which is why Intakt also uses it to find and report bugs (see the end of this page).
- **FrankenPHP** is the web server Intakt runs on. It keeps the application loaded between requests, which makes it fast, and it has live updates built in.

```mermaid
flowchart TD
  intakt[Intakt] --> talk[Talking to AI models]
  intakt --> agents[Reviewers with tools]
  intakt --> memory[Project memory]
  intakt --> engine[The engine room]
  intakt --> github[Talking to GitHub]
  intakt --> live[The live dashboard]
  intakt --> honest[Keeping it honest]
  talk --> p[Symfony AI Platform<br/>and AI Bundle]
  agents --> a[Symfony AI Agent<br/>and MCP]
  memory --> s[Symfony AI Store]
  engine --> e[Messenger, Workflow,<br/>Scheduler, Lock, RateLimiter]
  github --> g[HttpClient, Webhook optional]
  live --> l[FrankenPHP, Mercure, Turbo]
  honest --> h[Validator, Serializer, Clock,<br/>Reset, and the quality gates]
```

## Talking to AI models

**Symfony AI Platform** is like a universal power adapter: Intakt plugs into Anthropic, OpenAI or any OpenAI-compatible service through the same socket, so a reviewer can switch companies without its code changing.

What Intakt gets from it:
- **Several companies at once.** The two default reviewers come from different companies; one install talks to all of them. *(in progress)*
- **Answers as forms, not essays.** Instead of reading a model's free text and guessing, Intakt asks for a fixed shape ("verdict: approve or changes; a list of findings, each with a file and a line") and gets it back as a typed object, checked before it is used. *(in progress)*
- **A meter on every call.** Each answer reports the tokens it used, so Intakt knows what each review cost and how much of the week's allowance is left. *(in progress: every call's tokens are recorded already; using them for the week's budget is planned)*
- **Polite waiting.** When a company says "too many requests, try again in 40 seconds", Intakt gets that as a number, not a sentence to parse, and waits exactly that long. *(planned)*
- **A spare tire.** If one company's service is down, a call can fail over to another. *(planned)*
- **Tests without paying for AI.** Intakt's own tests use scripted answers (and real answers recorded once and replayed), so testing never calls a real model. *(in progress)*

**The AI Bundle** is the part that wires this into the application: which companies are configured, where their keys come from (a missing key simply switches that company off), and a table that maps Intakt's own words "strong", "standard" and "light" to each company's model names. *(in progress)*

## Reviewers with tools

**Symfony AI Agent** turns a model into an agent: a model that can use tools. Each reviewer role is an agent with read-only tools for the change it reviews (read a file, search the code, run a small test). *(in progress: reading files, listing folders and searching the code work; running a test is planned)*

```mermaid
flowchart LR
  reviewer[Reviewer agent] -- "read src/Foo.php" --> guard{Allowed?}
  guard -- inside the checkout, read-only --> tool[Tool runs]
  guard -- outside, or writes --> no[Refused, the reviewer is told why]
  tool --> reviewer
```

```mermaid
flowchart LR
  task[Task text] --> fence[Fence: marked as data]
  answers[Tool answers] --> fence
  fence --> reviewer[Reviewer sees data, not orders]
```

- **Guardrails:** before any tool runs, Intakt checks the request. A reviewer that asks for a file outside the code under review is refused. *(in progress)*
- **Fencing:** text that comes from a task or from the code under review is marked as data before the reviewer sees it, so it cannot give the reviewer orders. The mark closes only at a closing marker that repeats its own boundary, so a fake closing marker inside a file stays data. *(in progress)*
- **A failing tool is not a crash:** the reviewer gets an error message and carries on. *(in progress)* Losing the whole toolbox is different: when the reviewer cannot reach its tools at all, or loses them partway through, the review stops and is marked `no-tools` instead of guessing without them. *(built)*
- **Too much text is cut, and then the reviewer cannot say yes:** a task, a change or a file answer longer than about 100 kilobytes is cut, with a note from Intakt saying how much was left out. A reviewer that then still says "approve" is overruled: the review is marked `blocked`, because nobody can show it read the part it was not shown. *(built)*
- **MCP** is a standard way for AI agents to reach tools. You can drive Intakt from your own AI assistant over MCP: list your projects, start a review of a branch, and read a review's status, verdicts and list of things to fix. Each tool is labeled with what it does ("only reads", "changes something"), and Symfony's Security component decides whether a tool may write: starting a review needs your own key and a setting that allows it, whatever the assistant asks. *(built)* The reviewers reach their own tools the same way during a review: each review gets a private MCP door that only shows the code under review, and only that review's reviewers hold its key. *(built)*

## Project memory

**Symfony AI Store** is a searchable notebook. When a review finding is fixed, Intakt files it there; when a later change touches the same files, the reviewers are reminded of what went wrong before. It keeps the notebook in a small SQLite file of its own (one per environment, such as `var/memory_dev.db` or `var/memory_prod.db`, next to Intakt's database; the tests keep theirs in a folder of their own, one per test worker, so tests never write into the notebook a running Intakt uses or into each other's), so nothing extra has to run. To make the notes searchable, the text of each fixed finding is sent to OpenAI, which turns it into numbers; without an OpenAI account nothing is filed. Each project's notes stay its own: a reviewer is only reminded of its own project's mistakes.

## The engine room

These Symfony components keep many jobs running in the right order, without anyone watching:

| Component | Everyday comparison | What it does in Intakt | Status |
|---|---|---|---|
| **Messenger** | a to-do tray | builds, reviews and checks are messages handled in the background; a failed one is retried later | partly built: the two trays (commands and questions) exist, each takes only its own kind of message and turns the other kind away when Intakt first sends a message (not when a worker picks it up from the queue), and a review round puts one message per reviewer into a queue that workers empty in parallel; a message that finds its reviewer still with another worker comes back when that worker's hour is over, a rate-limited reviewer comes back after the provider's wait, and a message given up on marks its reviewer blocked; in the container image, three workers and the alarm clock run beside the web server, and one that stops is started again a second later |
| **Workflow** | a board game's rules | the steps of a review round and of each reviewer in it, and which moves are allowed, drawn as a diagram | partly built: a round (collecting, complete, judged) and each reviewer (queued, running, verdict, failed or blocked, and back to queued when its result could not be written down or the provider limited the call) are built; the steps of the whole review loop below are planned |
| **Scheduler** | an alarm clock | looks at each watched project's open pull requests every minute, checks usage, wakes up waiting tasks at their deadline, cleans up old branches weekly | partly built: every minute Intakt looks at the pull requests of each project that asks to be watched and starts one review for a new version once its checks are green (it looks again a minute later while the checks still run); reading pull requests from GitHub, checking usage, deadlines and the weekly cleanup are planned |
| **Lock** | a "one at a time" sign | stops too many calls to the same AI account at once, and two presses of a review's buttons from running at the same moment | built: at most three calls run at once for one AI account, and a reviewer that finds no free place goes back to the queue and tries again shortly; on a review page, "Start round N" and "Stop this reviewer" share one sign per review, checked only after the page's form and the review are found to be real, and never waited for: a press that arrives while another press for the same review is still being handled does nothing and says "Another press for this review is still running, so nothing was started." (or "... nothing was stopped."); the round-wide **Stop** button does not use the sign |
| **RateLimiter** | a speed limit | spaces calls out so an account stays within its company's limits | partly built: an AI account that hit its limit rests until the company's reset time, and its reviewers move to the next account of the same company meanwhile; spacing calls out before a limit is planned |
| **Console** | a remote control | commands such as running the bug reproductions or summarizing a change | in progress |
| **Uid** | a ticket number | every event gets a unique id that also says when it happened | in progress |
| **Process** | a helper that runs errands | runs git, test commands and the secret scan safely, with a time limit | in use |

The part that is built, a round and its reviewers (the technical drawing is `docs/architecture/review-round.dot`). A round is complete as soon as every reviewer has a verdict, has failed or is blocked, whichever reviewer is last; the state is stored in the database, so a restarted worker carries on:

```mermaid
stateDiagram-v2
  state "A round" as Round {
    [*] --> Collecting
    Collecting --> Complete: every reviewer has a verdict, failed or is blocked
  }
  state "Each reviewer" as Reviewer {
    [*] --> Queued
    Queued --> Running: a worker picks it up
    Running --> Verdict: the reviewer answers
    Running --> Failed: the call fails
    Running --> Queued: the result could not be written down, or the provider limited the call
    Running --> Queued: the worker is gone for an hour (once)
    Running --> Blocked: the worker is gone a second time
    Queued --> Blocked: the message was given up on
  }
```

The whole review loop as the Workflow component would draw it (planned):

```mermaid
stateDiagram-v2
  [*] --> Waiting
  Waiting --> Reviewing: checks are green
  Reviewing --> Changes: a reviewer leaves something to fix
  Changes --> Waiting: the Developer pushes a fix
  Reviewing --> Approved: the Code reviewers approve
  Approved --> [*]
```

## Talking to GitHub

- **HttpClient** makes the calls to GitHub (reading a pull request, posting a review), trying a read again when GitHub hiccups; a review is posted only once, so it never shows up twice.
- **Webhook and RemoteEvent** are an optional extra for installs that GitHub can reach from outside. They receive GitHub's notifications ("a check finished", "someone commented"), check that they really come from GitHub, and turn them into events Intakt reacts to a little sooner. Without them, Intakt simply asks GitHub often enough, and more often while something is waiting. *(planned, optional)*

## The live dashboard

- **FrankenPHP worker mode** keeps Intakt loaded in memory between requests, so pages answer fast. *(built in the container image's configuration; every release publishes the image, see [Releasing](../releasing.md#the-container-image))*
- **Mercure**, built into FrankenPHP, pushes updates to your browser as they happen, and **Turbo** (part of Symfony UX) updates just the part of the page that changed: a review finishing shows up without a reload. *(in progress: the page of a review that is still running shows the updates without a reload; the stop button stops a working reviewer the next time it reports progress, and the page then shows it as stopped)*

Keeping the application loaded has one catch: anything a part remembers would leak into the next request. Symfony's **reset** mechanism clears that memory between requests, and Intakt has a check that refuses any part that remembers things without a way to be cleared. A test sends many requests from two browsers through one loaded copy and confirms that no request sees the previous one's signed-in user, messages, database entries or review conversation. Some settings belong to the whole loaded copy rather than to one request: the default file permissions for new files (`umask`), the language and number format settings (the locale) and the environment variables in `$_ENV`. Intakt notes them when it starts and puts them back after every request, and the same test confirms that no request sees a change the previous one made to them. The test runs twice: once with one loaded copy kept for every request, and once with a fresh copy for each request (`FRANKENPHP_RESET_KERNEL=1`). *(in progress: the AI library's own parts are checked later)*

As a second safety net, a worker (one loaded copy of Intakt) does not live forever: it is swapped for a fresh one after it has answered a set number of requests (500 by default), or as soon as it holds more memory than allowed (256 MB by default). The swap happens after the answer has gone out, so nobody sees an error, and a small leak that slips past the reset can never grow without end. *(built in the container image's configuration; every release publishes the image, see [Releasing](../releasing.md#the-container-image))*

## Keeping it honest

- **Clock:** all time comes from one clock, so tests can freeze or fast-forward it; a check refuses code that reads the system time directly. *(in use)*
- **Validator and Serializer:** a reviewer's verdict is checked field by field; an unknown field or a missing one is refused rather than guessed. *(in progress)*
- **Quality gates:** `bin/check` runs every check (static analysis, code style, tests and Intakt's own rules) the same way on your machine and in CI. On your machine, `bin/check --reuse` can skip a second run of a folder that passed and has not changed since, all but the dependency audit; CI never skips. The walks through a freshly started install (the journeys, see [Checking the whole product](checking-the-whole-product.md)) run separately, with `bin/check --journeys`. *(in use)*

## Waiting before taking a new release

A brand-new release of a package is the one most likely to be broken or, rarely, tampered with: such problems are usually spotted and the release pulled within a few days. So Intakt holds every fresh release back for 3 days, a **cooldown**. *(built)*

- **PHP packages:** Composer leaves out any version published less than 3 days ago, and the checks fail when the lock file holds one, except for the owner's one-time exceptions for Symfony 8.2.0-BETA2 and for Twig 3.31.1, the security fix Twig released on 2026-11-24.
- **The dashboard's JavaScript packages:** the import map (the list in `importmap.php` that tells the browser where each JavaScript module of the dashboard lives) follows the same rule: while the newest release is less than 3 days old, `bin/console importmap:update` holds it back and can move the pin to an older release that is old enough.
- **A fingerprint on every module:** the import map the browser receives pins each module by its **integrity hash** (sha384), a fingerprint of the exact file Intakt serves. If the file's bytes change after the fingerprint was taken, the browser refuses to run it.

Both are set in `config/packages/asset_mapper.yaml`; the exact rules are in [the user guide](../reference/user-manual.md#the-release-cooldown-for-dependencies).

## Finding bugs in the beta

Symfony AI is new, and Intakt uses it hard on purpose. `upstream/` holds likely bugs in any library Intakt uses, not only Symfony AI: some are in Symfony Config, the Serializer, Messenger, DoctrineBundle, FrankenPHP or PHPStan. When something looks like a bug in one of them, Intakt writes the smallest possible test that shows it, keeps it under `upstream/`, and, with the owner's OK, reports it to that project. The report text waits in the same folder until the owner approves it. When a Symfony AI bug is small and clear (the fix stays in one package, the right behavior is not in doubt, nothing that others rely on breaks, and nobody upstream is already fixing it), Intakt can also prepare the fix itself: a patch and the pull request text, waiting in the same folder for the owner to approve and send. `intakt:upstream:run` re-runs all of them against the pinned versions, so it is obvious when a bug has been fixed upstream. Each report also notes whether the bug is still there in the next version being built upstream, whether someone already reported it there, and what to do next (file it, point to the existing report, or drop it). The current list is in the "Found in the beta" section of the [highlights page](https://shinyredapples.github.io/intakt/highlights/) (its rows are in [`docs/highlights.d/`](../highlights.d/)), and the checks fail when a folder under `upstream/` is missing from it.
