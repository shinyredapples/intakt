# Work intake from GitHub issues

Plan task T47. `Plugin/WorkSource` is the contract. No implementation exists yet and nothing calls it. The rules are those of [Choosing work](../design/05-architecture/03-choosing-work.md) (step 1, eligible, and step 5, prioritized) and [Labels](../design/05-architecture/04-labels.md).

## Contract (`Plugin/WorkSource/Contract`)

- `WorkSource::nextWork(project, WorkerState)` answers a `WorkPick`: a `Task` (number, title, and `reworkOf`, the number of its open pull request or null) or a `NothingPicked` reason. A worker state other than `free` (`no-free-slot`, `paused`, `draining`, `held-by-budget`) is meant to return before any forge read, so an idle worker makes no forge call.
- `WorkSource::queue(project)` answers a `WorkQueue`: the ready tasks in pick order and the held ones (`HeldTask`, with a reason), or `incomplete`. `TaskNotQueued` is the failure for a task that is not in the queue.
- `WorkSource::failedForGood(project, number, reason)` records that a task failed for good, so the tasks that wait on it are held with that reason.

## The `eligibility_label` key

The `watch` section of a project takes the optional `eligibility_label`: the issue label that makes a task eligible. When the key is left out it is `intakt` (the private constant `DEFAULT_ELIGIBILITY_LABEL` of `Core/Watcher/Infrastructure/WatchConfiguration`, the one place it is spelled, so the Watcher does not depend on the GitHub work source), so each project has its own label, apart from the existing loop's (plan task T70). `WatchConfiguration` refuses a blank value, including one of only Unicode whitespace such as a no-break space, with the same check as the `repository`, `base` and `task` keys. The resolved label is part of the container parameter `intakt.watcher.projects`, but no running code reads it yet: the work source that will (the implementation arrives in part 2 of #1602) does not exist. Tests: `tests/Core/Watcher/Infrastructure/WatchConfigurationTest.php`, and `tests/Application/ProjectTiersTest.php` for the loaded parameter.

`Plugin/GitHubWorkSource/Domain/IntakeRules` takes the project's label: an issue is a candidate only when it carries that label and not `idea`, so a task that carries only another label, such as the existing loop's, is neither ready nor held, and a task that carries both is eligible. Intakt knows no list of the loop's labels; every label other than the project's counts the same. Tests: `tests/Plugin/GitHubWorkSource/Domain/IntakeRulesTest.php` (`testATaskCarryingOnlyTheExistingLoopsLabelIsNotEligibleUnderTheDefaultLabel`, `testATaskCarryingTheProjectsLabelBesideTheExistingLoopsLabelIsEligible`).
