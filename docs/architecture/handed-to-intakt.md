# Pull requests handed to Intakt

While Intakt runs beside the existing loop, Intakt opens its pull requests under its own GitHub App identity and the loop leaves every pull request that identity opened (owner decision 2 of 2026-10-31 in `docs/plan.md`). Plan task T71 (issue #3001, part 1 of #353) makes Intakt recognize those pull requests as handed over to it, so later parts treat them as counted. This part records the recognition only: posting Intakt's review with the round's one-time token, asking the owner for protected heads, and the merge through Intakt alone are planned (parts 2 and 3 of #353). The weekly report on the loop's own heads ([Weekly report](weekly-report.md)) reads nothing of it and is unchanged.

## What counts as handed over

A pull request counts as handed to Intakt when Intakt's own ledger holds a `change_opened` line for it: the line `PushBranch` appends after the GitHub App opened or updated the pull request ([Branch push](branch-push.md)), with the same `project` and the same `change` (the pull request's address, which is also the change id the listing returns). Nothing the forge reports about the pull request decides it: its author, title, body, labels and branch are text others can shape, while the ledger holds only what Intakt's push wrote.

Not handed over:

- a pull request with no `change_opened` line, such as one the loop opened;
- a `change_opened` line of another project with the same address, because projects are told apart;
- a pull request whose last record is a `change_intended` with no `change_opened` (the forge call failed, the record was refused, or the process stopped in between): the ledger does not know that the pull request exists, so it is not recognized from it.

## Where it happens

`Core/Watcher/UseCase/WatchPullRequests/ChangesHandedToIntakt::recognizeAmong()` runs in each watch tick ([Forge contract](forge-contract.md), the Scheduler tick), inside `ChangedHeads`' database transaction, after the listing returned the changed pull requests and before they are queued. It reads the ledger lines that mention any of the changed pull requests (`Ledger::readMentioning()`, one text `"change":<address>` per pull request), keeps the `change_opened` and `change_handed_to_intakt` lines of the tick's project (a line that is not valid JSON is skipped), and appends one `change_handed_to_intakt` line (`project`, `change`, `head`: the head the listing named) through `WatcherLedger` for each changed pull request with a `change_opened` line and no `change_handed_to_intakt` line yet. A line the ledger refuses throws `WatcherEventNotRecorded`, which rolls the transaction back with the listing's record, so the next listing names the same pull requests again.

Only pull requests the listing names as changed (new, or with a new head or update time) are looked at. A pull request listed before its `change_opened` line was written is therefore recognized the next time the listing names it as changed. The line is a fact, not a state: a second `change_handed_to_intakt` line for the same pull request (two workers both seeing it as changed) says the same thing, and readers treat the lines as a set.

Tests: `ChangesHandedToIntaktTest` (group `persistence`, on the Doctrine ledger), `WatchPullRequestsHandlerTest::testATickRecordsTheListedPullRequestIntaktOpenedAsHandedToIntaktBeforeItsHeadDecision` and `WatchTickOverRecordedGitHubTest::testATickRecordsThePullRequestIntaktOpenedAsHandedToIntaktAndTheOtherNot` (recorded GitHub answers, read back with `intakt:ledger:events`).
