# Build task

`php bin/console intakt:build:task <project> <issue>` (`BuildTaskCommand` in `src/Core/Build/UseCase/BuildTask/`) takes one open issue of a project from task text to a build clone. It calls `BuildTask::run($project, $number): TaskOutcome`, the use case the build lane will call later. It holds no slot, queue, admission, schedule or state: one run builds one task, and the caller names the issue, so the eligibility and order rules of the work source are not applied. This part covers three steps; the build job, the hand-back and pre-push checks, the signed push and the pull request are later parts, and so is the refusal of a second run for an issue that has an open pull request.

## Steps

`TaskStep` lists them in order, and a run stops at the first one that fails:

1. **Task.** `Plugin/GitHubForge` `IssueReads::listOpen($project, null)` lists the project's open items; the step passes when one with the issue's number is an issue (a pull request with that number is no task). The title and body are read here but not yet used: no model is called in this part, and a `Source:` line is not honored, because the trust check of its author stays with `intakt:build:clone --trusted-author`.
2. **Mirror.** `Git\Contract\ProjectMirror::update($project, $remote)` (implemented by `UpdateMirror`, see [Bare mirror](mirror.md)). The remote is the `repository` of the project's watch section (`Watcher\Contract\ProjectRepositories::of()`); a project without one fails this step.
3. **Clone.** `Git\Contract\BuildClones::make($project, $branch, $directory)` (implemented by `Git\Infrastructure\MirrorBuildClones` over `MakeBuildClone`, see [Build clone](build-clone.md)). The branch is `intakt/issue-<number>`, chosen here because nothing else names a task's branch yet. The folder is `var/builds/<project>-<number>` (the parameter `intakt.build.clones_directory`, `Build\Contract\BuildServices::CLONES_DIRECTORY`). A folder that already exists fails the step, so a second run for the same issue stops here until the folder is removed.

## Outcomes

A step failure is a `ReportableFailure`, an `InvalidArgumentException`, an `IOException` or `RepositoryAttributesRefused` from the step; `run()` turns it into `TaskOutcome::stoppedAt(completed steps, step, reason)` and runs nothing after it. A failure to record a step in the ledger is not an outcome: `run()` throws `TaskStepNotRecorded`, so a step the ledger does not hold never counts as done.

Each step appends one ledger event, with a JSON payload of `project` and `task` (the issue number), plus `reason` on a failure and `branch` on `clone_made`: `task_read` or `task_not_read`, `mirror_updated` or `mirror_not_updated`, `clone_made` or `clone_not_made` (`LedgerEventKind`; `intakt:ledger:events` prints them). The dashboard and `intakt:status` do not show these events yet.

The command prints `<step> done` for each step that passed, then `clone <directory>` and exit 0, or an error naming the failed step and its reason and exit 1. A failure is not retried here: the run ends with the failure recorded.

Tests: `BuildTaskTest` (real git on a temporary repository for the passing run and for a missing remote, stubs for the rest), `BuildTaskCommandTest`. In the test environment `tests/Core/Build/services.yaml` replaces the forge's listing with `ScriptedIssueReads`, which lists the issue numbers named in the variable `BUILD_ISSUES`.
