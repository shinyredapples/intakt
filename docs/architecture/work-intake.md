# Work intake from GitHub issues

Plan task T47. `Plugin/WorkSource` is the contract. No implementation of it exists yet and nothing calls it; `Plugin/GitHubWorkSource` holds the parts of the GitHub one built so far: its rules (`Domain\IntakeRules`) and its stored state (below), which nothing calls yet either. The rules are those of [Choosing work](../design/05-architecture/03-choosing-work.md) (step 1, eligible, and step 5, prioritized) and [Labels](../design/05-architecture/04-labels.md).

## Contract (`Plugin/WorkSource/Contract`)

- `WorkSource::nextWork(project, WorkerState)` answers a `WorkPick`: a `Task` (number, title, and `reworkOf`, the number of its open pull request or null) or a `NothingPicked` reason. A worker state other than `free` (`no-free-slot`, `paused`, `draining`, `held-by-budget`) is meant to return before any forge read, so an idle worker makes no forge call.
- `WorkSource::queue(project)` answers a `WorkQueue`: the ready tasks in pick order and the held ones (`HeldTask`, with a reason), or `incomplete`. `TaskNotQueued` is the failure for a task that is not in the queue.
- `WorkSource::failedForGood(project, number, reason)` records that a task failed for good, so the tasks that wait on it are held with that reason.

## The `eligibility_label` key

The `watch` section of a project takes the optional `eligibility_label`: the issue label that makes a task eligible; when the key is left out it is unset, and no code reads it yet (the implementation arrives in part 2 of #1602). `WatchConfiguration` refuses a blank value, including one of only Unicode whitespace such as a no-break space, with the same check as the `repository`, `base` and `task` keys. Test: `tests/Core/Watcher/Infrastructure/WatchConfigurationTest.php`.

## Stored state (`work_source_issue`, `work_source_validator`, `work_source_failure`)

Issue #2906, part 1 of #2666. Three Doctrine entities in `Plugin/GitHubWorkSource/Domain`, mapped as the `GitHubWorkSource` entry of `config/packages/doctrine.yaml`, with migration `Version20261120100000`:

- `StoredIssue` (`work_source_issue`, key project and number): what the last complete listing showed of one open issue or pull request. It keeps the title (cut to 255 characters), labels, author, update time and milestone due date (text in UTC with microseconds, `Y-m-d H:i:s.u`, because a datetime column keeps whole seconds and an edit within the same second would go unseen), whether it is a pull request, the `Waits for` numbers, the `Waits for` lines that could not be read and the numbers a pull request closes, and what its history said once read (`vouch()`): whether author and last editor had write access, and the labels whose last application was by such an account. `trusted` is null until the history was read. The body is not kept, because only the numbers it names steer the queue. `queued()` gives the row back as the `QueuedIssue` the rules take (with an empty body, and untrusted when the history was not read).
- `IntakeValidator` (`work_source_validator`, one per project): the validator of the last complete listing.
- `TaskFailure` (`work_source_failure`, key project and number): the reason a task failed for good. It is a table of its own, so removing the row of a closed task keeps its reason and its dependents stay held.

`Infrastructure\IntakeStore` persists them:

- `record(project, issues, ?validator)` makes the project's rows those of one complete listing in one flush: a stored item is updated in place (`StoredIssue::replaceWith`), a new one is added, a stored item the listing no longer shows loses its row, and the validator is stored, replaced, or removed when the forge sent none, so the next listing is a full one. An issue of another project is an `InvalidArgumentException`, and nothing is written. Failures are never touched.
- `issuesOf(project)` and `validatorOf(project)` read them back.
- `failForGood(project, number, reason)` stores or replaces the reason, only for a task the stored rows show (`TaskNotQueued` otherwise); `failuresOf(project)` answers the reasons by number.

Every method detaches the project's rows from the entity manager before it reads and again when it ends, one by one and never by clearing it, because a worker keeps its entity manager between ticks and what another worker stored since is the truth, while the caller's own pending work must survive. The store takes no lock: the listing that calls `record()` and the pick that calls `failForGood()` are other parts of #2666 and of #329. Test: `tests/Plugin/GitHubWorkSource/Infrastructure/IntakeStoreTest.php` (group `persistence`, SQLite); `tests/Application/MigrationsMatchMappingTest.php` checks the migration against the mapping.
