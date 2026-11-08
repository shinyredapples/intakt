# Checking the whole product

Every change proves itself, and reviewers read what changed. Neither shows whether Intakt as a whole still works after hundreds of changes. **Journeys** do: each one starts a fresh, empty copy of Intakt, then does what a person would do and checks what they would see.

A journey is not part of the checks every change passes (`bin/check` and `bin/check --fast` leave them out), because starting a whole copy takes longer. Run them with:

```
bin/check --journeys
```

It starts a new copy for each journey and stops everything that journey started when it ends, so one journey cannot upset the next. A passing run ends with `"result":"pass"`. A failing one names the journey and the step that broke, for example `journey "first install and setup" failed at step "open the reviews page"`, so you know where to look.

Journeys never call an AI company or a forge and need no keys; they use the sample project only.

A third helper, the round driver, starts a review on the sample project and moves it forward one small piece of work at a time, reading the live updates after each piece until the review ends; if the review has not ended after two and a half minutes, it stops and says which review got stuck and what it last showed, before the journey's own time runs out. Two stand-ins make that possible once a journey runs a review. In the copy a journey starts, every reviewer is a pretend one that approves at once, without asking an AI. And the live updates the review page would receive are written to a file instead of being sent, so the journey can read them afterwards and check what a person watching the page would have seen. The live progress journey below uses all of this.

## What is covered

| Journey | The path | Status |
|---|---|---|
| First install and setup | a new install gets its database, shows an empty projects page, is given the sample project (a second time changes nothing), and shows an empty reviews page | built |
| A task picked, built, reviewed and merged on the demo repository | the whole life of a task | planned |
| The dashboard showing live progress | the watcher sees a pull request on the sample project (read from a recording, not a forge) and starts a review, the review page shows it in progress, every live update the review sends fits the page a person watching it holds, until the judge has ruled and the page shows it judged; whether the browser itself listens for those updates is checked by a separate test, not by this journey | built |
| An owner decision answered | a question that waits for you, and your answer | planned |

A nightly run, and a run you start on demand in GitHub, that files an urgent task when a journey fails, are planned too.

The technical side is in [Journeys](../architecture/journeys.md).
