# Work intake from GitHub issues

Plan task T47. `Plugin/WorkSource` is the contract. No implementation exists yet and nothing calls it. The rules are those of [Choosing work](../design/05-architecture/03-choosing-work.md) (step 1, eligible, and step 5, prioritized) and [Labels](../design/05-architecture/04-labels.md).

## Contract (`Plugin/WorkSource/Contract`)

- `WorkSource::nextWork(project, WorkerState)` answers a `WorkPick`: a `Task` (number, title, and `reworkOf`, the number of its open pull request or null) or a `NothingPicked` reason. A worker state other than `free` (`no-free-slot`, `paused`, `draining`, `held-by-budget`) is meant to return before any forge read, so an idle worker makes no forge call.
- `WorkSource::queue(project)` answers a `WorkQueue`: the ready tasks in pick order and the held ones (`HeldTask`, with a reason), or `incomplete`. `TaskNotQueued` is the failure for a task that is not in the queue.
- `WorkSource::failedForGood(project, number, reason)` records that a task failed for good, so the tasks that wait on it are held with that reason.

## The `eligibility_label` key

The `watch` section of a project takes the optional `eligibility_label`: the issue label that makes a task eligible; when the key is left out it is unset, and no code reads it yet (the implementation arrives in part 2 of #1602). `WatchConfiguration` refuses a blank value, including one of only Unicode whitespace such as a no-break space, with the same check as the `repository`, `base` and `task` keys. Test: `tests/Core/Watcher/Infrastructure/WatchConfigurationTest.php`.
