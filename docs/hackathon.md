# Intakt at the Symfony AI & MCP Hackathon

Intakt was built for the Symfony AI & MCP Hackathon, from 2026-10-26 to 2026-11-22. This page says what was planned,
what was achieved inside that window, and what comes next. Development continues after the hackathon.

## The plan

The first release is the review loop ([spec](spec.md), [plan](plan.md)): a panel of reviewers on models from different
AI labs reviews a change, a judge marks every finding against the task and tells the builder what to act on, finding
ids survive from round to round, your own agent runs the loop over MCP until the verdict is clean, a live dashboard
shows each round as it streams, and `docker compose up` with a sample repository gets a juror to the first verdict in
five minutes. The larger aim comes after it: you give Intakt a spec, and Intakt turns it into small tasks, builds each
one and merges what passes review.

## The timeline

The repository's first commit with content is from 2026-10-26, the first day of the hackathon; the commit of
2026-10-24 is GitHub's empty initial commit. Everything else was built inside the window. The milestones reached, with
the dates from [the journey](journey.md#milestones):

- **2026-10-26:** the design is written and the first pull requests are merged.
- **2026-10-27:** 31 plan tasks with acceptance criteria, in the milestones M1 to M5.
- **2026-11-03:** M3 Agents and memory: the MCP server and client, the Store briefing, GitHub.
- **2026-11-06:** M1 Review core (one reviewer, a schema-valid verdict in the ledger) and M4 Live dashboard.
- **2026-11-11:** the first release, v0.1.0, then a release train twice a day.
- **2026-11-12:** M2 Panel and rounds: a configured panel, quorum, finding ids that persist, the judge.
- **2026-11-21 (planned):** M5 Demo (`docker compose up`, the sample repository, the five-minute path) is due, and the
  feature freeze starts.

## What was achieved

- **The five-minute start and the keyless demo:** clone, `docker compose up`, and a review of the sample change, or a
  recorded real review played with no provider key ([five-minute start](../README.md#five-minute-start),
  [timed run](../README.md#timed-run)).
- **The review page:** every round with its reviewers, their verdicts, the findings and the judge's call on each
  ([reading a verdict](user/README.md#reading-a-verdict), [a tour of the dashboard](guide/a-tour-of-the-dashboard.md)).
- **Trends:** figures about the reviews of every project as tiles ([the Trends page](user/README.md#the-trends-page)).
- **The review bench:** the panel found 11 of 15 planted defects (measured, 2026-11-16) with 2 false alarms
  ([review bench](../README.md#review-bench)).
- **MCP tools and the agent skill:** your agent starts reviews, reads the findings and loops until the verdict is clean
  ([MCP](user/mcp.md), [the skill](../skills/intakt-review/README.md)).
- **The quality gates:** `bin/check` runs every gate CI runs on every change ([quality gates](architecture/quality-gates.md)).
- **Upstream reports:** likely bugs in Symfony, Symfony AI and other libraries, each with a minimal reproduction, and
  fixes that are in Symfony 8.2.0-BETA2 ([found and fixed upstream](../README.md#found-and-fixed-upstream),
  [upstream reproductions](../upstream/README.md), "Found in the beta" on
  [Highlights](https://shinyredapples.github.io/intakt/)).
- **Built by an AI loop:** the repository itself, in figures and examples ([showcase](showcase/README.md)).

Counted on 2026-11-20 from the repository: 7,289 commits on main (counted), 27 upstream reports in `upstream/`
(counted). Counted from the GitHub API to 2026-11-13, the latest GitHub count, as listed in
[the journey](journey.md): 614 merged pull requests (counted) and 773 closed issues (counted, 761 done and 12 dropped).

## AI use

- AI agents in a build loop wrote the code, the docs and the tests.
- Every change went through a pull request with reviews by models from two AI labs, CI with the repository's quality
  gates, and an owner approval step for protected paths.
- The owner set the plan, the decisions and the priorities.
- The repository as an example of this way of working, in figures and examples: [the showcase](showcase/README.md).

## After the hackathon

Development continues. The next milestones, from [the plan](plan.md) and the
[GitHub milestones](https://github.com/shinyredapples/intakt/milestones):

- **M6 Shadow:** Intakt reviews pull requests on GitHub and posts the result.
- **M7 Builder half:** Intakt builds a task from an issue to a pull request.
- **M8 Switch:** Intakt runs its own loop.

How Intakt got here, milestone by milestone: [the journey](journey.md).
