# Work intake from GitHub issues

Plan task T47. `Plugin/WorkSource` is the contract, `Plugin/GitHubWorkSource` its GitHub implementation. Nothing calls it from a build loop yet: the console command `intakt:queue:show` is its only door. The rules are those of [Choosing work](../design/05-architecture/03-choosing-work.md) (step 1, eligible, and step 5, prioritized) and [Labels](../design/05-architecture/04-labels.md).

## Contract (`Plugin/WorkSource/Contract`)

- `WorkSource::nextWork(project, WorkerState)` answers a `WorkPick`: a `Task` (number, title, and `reworkOf`, the number of its open pull request or null) or a `NothingPicked` reason (`worker-not-taking-work`, `listing-incomplete`, `trust-not-checked`, `no-task-ready`). A worker state other than `free` (`no-free-slot`, `paused`, `draining`, `held-by-budget`) returns at once, before any listing, read or trust check, so an idle worker makes no forge call.
- `WorkSource::queue(project)` answers a `WorkQueue`: the ready tasks in pick order and the held ones (`HeldTask`, with a reason), or `incomplete`.
- `WorkSource::failedForGood(project, number, reason)` records that a task failed for good; its dependents are held with that reason, also after the failed task is closed.

## One listing per tick

`GitHubForge\Contract\IssueReads::listOpen` (`GitHubIssueReads`) reads every page of `GET /repos/{owner}/{repo}/issues?state=open` (100 per page, at most 10 pages; pull requests come in the same listing and carry `pull_request`). It reads on while a page is full or its `Link` header names a `next` page, and stops at a page that is short and names none. A short page that names a next page (an empty one included) is a page GitHub cut short. That, any other failure of a page (a status other than 2xx, a rate limit, a transport error, an answer that is not a list, an unreadable item) and a listing past the page cap are a `ForgeReadFailed`; a short listing is never returned. `GitHubWorkSource` catches it, logs an error (`The open issues of the project were not listed completely, so no task is picked.`, with the project and the exception) and picks nothing and closes no wait. The listing takes its own reserve slot per project (`issues:<project>`), beside the watcher's.

The order is computed over the whole listing, after the last page. The first page carries an `ETag`; it is stored (`work_source_validator`) only when the listing fit on one page, and the next listing sends it as `If-None-Match`. A `304` answers from the stored rows.

## History of an item

`IssueReads::historyOf(project, number)` asks GitHub's GraphQL API (`issueOrPullRequest`) for the author, the last editor and the last 100 label events of an issue or pull request, and answers an `IssueHistory`: the editor (the author when nobody edited the text) and, for each label, the account that applied it last, in the order of those applications (null for a deleted account). An app is named with the `[bot]` suffix the permission check knows. An answer with `errors`, without the item, without an editor or author login, or without the label events is a `ForgeReadFailed`, which makes the listing incomplete.

## Stored state (`work_source_issue`, `work_source_failure`)

One `QueuedIssue` row per open issue or pull request of the last complete listing: title, labels, author, update time, milestone due date, the `Waits for` numbers, the numbers a pull request closes, and what its history said: the last editor, whether author and last editor had write access, and the labels whose last application was by an account with write access. The history is read only for an item that can steer the queue: an issue with the project's eligibility label and no `idea`, or a pull request with a closing keyword. It is read when such an item is new or its update time moved, and for a stored item that became one (another `eligibility_label`), also when the listing is unchanged or answered `304`; each read costs one GraphQL call and one permission check per distinct account. A row whose update time is unchanged keeps its history, and takes the milestone due date of each full listing, because a due date moves without moving the update time. Rows of items no longer open are removed. Nothing is written until every read of the listing succeeded. Concurrent listings of a project are refused (a lock tried without waiting; the busy one counts as an incomplete listing).

A failure for good is a `TaskFailure` row of its own (`work_source_failure`), recorded only for a task the last listing showed and never removed by a listing, so closing a failed task keeps its dependents held. `nextWork` reads the failures again after the trust checks of the task it is about to pick and plans once more, so a failure another worker recorded during the tick keeps that task, and the tasks that wait on it, from the pick.

## Eligible, held, ordered (`Domain\IntakeRules`)

- Eligible: not a pull request, carries the project's eligibility label (`intakt`, or the `eligibility_label` of the project's `watch` section) applied last by an account with write access, no `idea` label, and author and last editor had write access when read (`SteeringAccounts`, which asks GitHub's permission API through `SteeringTrustCheck`, never cached). An edit by an untrusted account moves the update time, so the history is read again and the task is ineligible until a trusted account saves the issue. Write access is asked again, for author and last editor, in `nextWork` before the pick; a task that lost it is skipped with a warning, and a check that cannot be made picks nothing.
- Trusted pull request: one whose author and last editor have write access. Only its closing keywords (`Closes`, `Fixes` or `Resolves` with `#n`) count.
- Held: a task that failed for good (`failed for good: <reason>`), a task that waits for one that failed, open or closed (`depends on #n, which failed for good: <reason>`), and a task with a `Waits for #n` line naming an issue the listing shows open (`waits for #n, #m`). A `Waits for` number that is a trusted pull request with a closing keyword stands for the tasks it closes; one that closes the waiting task itself is its own pull request and no wait. Any other pull request is waited for until it is closed. `Waits for` lines are read from the issue body only (a line of its own, several numbers allowed). An issue the complete listing does not show counts as closed; a number equal to the task's own is ignored. The state of every waited-for issue comes from the one listing or from the stored rows, so a wait makes no read per reference.
- Order: `urgent` first, then tasks with a trusted open pull request that closes them, then priority (`priority:urgent|high|normal|low`, only from a label applied last by an account with write access; of two such labels the one applied last counts; none is `normal`), milestone due date (none last) and number.
- Rework: the lowest trusted open pull request that closes a task is its `reworkOf`. It comes from the tick's listing, never from a stored record, so a task whose rows were lost is still picked as a rework.

## Not in this task

Status labels (T19), task branches (Intakt names none yet, so a pull request is linked to its task by its closing keyword only), the hand-over of a build (T59), `take-over`, `hands-off` and `Waits for` notes in comments are not built; comments are not read.

Tests: `tests/Plugin/GitHubWorkSource/` (`Domain/IntakeRulesTest`, `Domain/IssueReferencesTest`, `GitHubWorkSourceTest`, `CompleteListingTest`, `ShowQueueCommandTest`) and `tests/Plugin/GitHubForge/ProjectSteeringAccountsTest.php`.
