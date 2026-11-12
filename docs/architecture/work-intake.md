# Work intake from GitHub issues

Plan task T47. `Plugin/WorkSource` is the contract, `Plugin/GitHubWorkSource` its GitHub implementation. Nothing calls it from a build loop yet: the console command `intakt:queue:show` is its only door. The rules are those of [Choosing work](../design/05-architecture/03-choosing-work.md) (step 1, eligible, and step 5, prioritized) and [Labels](../design/05-architecture/04-labels.md).

## Contract (`Plugin/WorkSource/Contract`)

- `WorkSource::nextWork(project, WorkerState)` answers a `WorkPick`: a `Task` (number, title) or a `NothingPicked` reason (`worker-not-taking-work`, `listing-incomplete`, `trust-not-checked`, `no-task-ready`). A worker state other than `free` (`no-free-slot`, `paused`, `draining`, `held-by-budget`) returns at once, before any listing, read or trust check, so an idle worker makes no forge call.
- `WorkSource::queue(project)` answers a `WorkQueue`: the ready tasks in pick order and the held ones (`HeldTask`, with a reason), or `incomplete`.
- `WorkSource::failedForGood(project, number, reason)` records that a task failed for good; its dependents are held with that reason.

## One listing per tick

`GitHubForge\Contract\IssueReads::listOpen` (`GitHubIssueReads`) reads every page of `GET /repos/{owner}/{repo}/issues?state=open` (100 per page, at most 10 pages; pull requests come in the same listing and carry `pull_request`). Any failure of a page (a status other than 2xx, a rate limit, a transport error, an answer that is not a list, an unreadable item) or a listing past the page cap is a `ForgeReadFailed`; a short listing is never returned. `GitHubWorkSource` catches it, logs an error (`The open issues of the project were not listed completely, so no task is picked.`, with the project and the exception) and picks nothing and closes no wait. The listing takes its own reserve slot per project (`issues:<project>`), beside the watcher's.

The order is computed over the whole listing, after the last page. The first page carries an `ETag`; it is stored (`work_source_validator`) only when the listing fit on one page, and the next listing sends it as `If-None-Match`. A `304` answers from the stored rows.

## Stored state (`work_source_issue`)

One `QueuedIssue` row per open issue or pull request of the last complete listing: title, labels, author, update time, milestone due date, the last editor and whether author and editor had write access when the item was last read, the `Waits for` numbers, the numbers a pull request closes, and the failed-for-good reason. A row whose update time is unchanged is not read again; a changed or new task candidate (open, eligibility label, not `idea`) costs one GraphQL read for its last editor and a permission check per distinct account. Rows of items no longer open are removed. Nothing is written until every read of the listing succeeded. Concurrent listings of a project are refused (a lock tried without waiting; the busy one counts as an incomplete listing).

## Eligible, held, ordered (`Domain\IntakeRules`)

- Eligible: not a pull request, carries the project's eligibility label (`intakt`, or the `eligibility_label` of the project's `watch` section), no `idea` label, and author and last editor had write access when read (`SteeringAccounts`, which asks GitHub's permission API through `SteeringTrustCheck`, never cached). An edit by an untrusted account moves the update time, so the editor is read again and the task is ineligible until a trusted account saves the issue. Write access is asked again, for author and last editor, in `nextWork` before the pick; a task that lost it is skipped with a warning, and a check that cannot be made picks nothing.
- Held: a task that failed for good (`failed for good: <reason>`), a task that waits for one that failed (`depends on #n, which failed for good: <reason>`), and a task with a `Waits for #n` line naming an issue the listing shows open (`waits for #n, #m`). `Waits for` lines are read from the issue body only (a line of its own, several numbers allowed). An issue the complete listing does not show counts as closed; a number equal to the task's own is ignored. The state of every waited-for issue comes from the one listing or from the stored rows, so a wait makes no read per reference.
- Order: `urgent` first, then tasks with an open pull request (a listed pull request whose body has a closing keyword, `Closes`, `Fixes` or `Resolves` with `#n`), then priority (`priority:urgent|high|normal|low`; none is `normal`; two values count as the less urgent one), milestone due date (none last) and number.

## Not in this task

Status labels (T19), reading a pull request by the task's branch, a pull request named in `Waits for`, `take-over`, `hands-off` and `Waits for` notes in comments are not built; comments are not read.

Tests: `tests/Plugin/GitHubWorkSource/` (`Domain/IntakeRulesTest`, `Domain/IssueReferencesTest`, `GitHubWorkSourceTest`, `CompleteListingTest`, `ShowQueueCommandTest`) and `tests/Plugin/GitHubForge/ProjectSteeringAccountsTest.php`.
