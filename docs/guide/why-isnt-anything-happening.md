# Why isn't anything happening?

Sometimes the dashboard looks quiet: nothing building, a task that hasn't moved. Usually Intakt is waiting on purpose, and the reason is written on the card. This page lists the common reasons and what, if anything, to do. Intakt is still being built, so this describes the decided design.

## Start here

```mermaid
flowchart TD
  q[Nothing is moving] --> w{Anything in<br/>Waiting on you?}
  w -- yes --> you[That is the hold-up:<br/>the card says what to do]
  w -- no --> d{Dashboard says<br/>draining or paused?}
  d -- yes --> upd[An update or a pause:<br/>it resumes by itself, or you resume it]
  d -- no --> b{Budget badge?}
  b -- yes --> money[The AI allowance is near its limit<br/>or the pace says slow down]
  b -- no --> r{Several pieces of work<br/>waiting for review?}
  r -- yes --> cap[Builders pause until<br/>reviews catch up]
  r -- no --> t[Look at the task's card:<br/>its badge names the reason]
```

## Common reasons

| What you see | What it means | What to do |
|---|---|---|
| A card in **Waiting on you** | only a person can move it on | do what the card says |
| **Draining** | Intakt is updating itself; running jobs finish first | nothing: it resumes in minutes |
| **Paused** | someone paused the project or the install | resume it from the dashboard |
| **budget** badge | the AI allowance is near its limit, or this week is spending fast | wait for the refill, or add an account ([costs](accounts-and-costs.md)) |
| **Only one builder** is running | the week's pace says slow down | normal; it speeds up when there is room |
| Builders paused, reviews waiting | finished work is piling up for review, so building more would only go stale | nothing: building resumes as reviews finish. An `urgent` task can still start |
| **CI** badge for a long time | GitHub hasn't finished, or never started, the checks | Intakt nudges GitHub after a few minutes and tells you if that didn't help |
| **on hold** | the task needs something outside it (another task, a change to Intakt) | nothing: it comes back by itself when the reason is gone |
| **dependency** badge | it `Waits for` another task | finish or unblock that task |
| A task stays in **Backlog** | it isn't ready: unclear, too big, or missing the `intakt` label | read Intakt's comment on the issue and add what it asks ([writing a good task](writing-a-good-task.md)) |
| A task never appears | no `intakt` label, or written by someone without write access | add the label yourself |
| A project gets nothing built or reviewed | its share is 0, which pauses it (open pull requests too) | raise its share above 0 |
| A project gets no new builds, but its reviews go on | another project has `urgent` work, which takes the next free build slot | nothing: it gets the slot when the urgent work is done |
| A red item on the board | a part of Intakt itself is failing (a sandbox, a package install, the boot gate); the maintainer agent tries to fix it first | nothing, unless it also turns up in Waiting on you |
| A red card in Waiting on you | a broken part only you can fix (an expired login, a credential, the host), or one the maintainer agent could not fix | follow the card: it names the step and the cause |

## Still stuck?

Open the task's card: its timeline shows every step with its reason, and the live log shows what the current job is doing right now. If a card has been somewhere much longer than usual with no reason given, that is a bug in Intakt: the trends on the dashboard and the record behind every card make it easy to report.
