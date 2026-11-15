# Build task

Plan task T56, part 1 (#2037). `php bin/console intakt:build:task <project> <issue> --remote=<remote> --branch=<branch> --directory=<folder>` (`BuildTaskCommand`) calls `UseCase/BuildTask/BuildTask::run(BuildTaskRequest): BuildTaskOutcome` (marked `#[NoDiscard]`) in `Core/Build`. The build lane (#1568) will call the same use case; it holds no slot, queue, admission, schedule or state machine, and one run builds one task. The remote, the branch and the folder are options the caller gives, as the single commands take them as arguments; no naming scheme derives them.

## The steps

1. **Task:** `IssueReads::listOpen(<project>, null)` (the GitHub forge's contract) lists the open issues and the run takes the one numbered `<issue>`. The caller names the issue, so the eligibility and order rules of intake do not apply. A pull request of that number is no task; a forge read that fails (`ForgeReadFailed`, `ForgeRateLimited`) or an issue the listing lacks ends the run here. The result holds the `ListedIssue` (title and body, untrusted text) for the steps that come later.
2. **Mirror:** `BuildCheckout::updateMirror(<project>, <remote>)`, the Git module's contract for [`intakt:mirror:update`](mirror.md).
3. **Clone:** `BuildCheckout::makeClone(<project>, <branch>, <folder>)`, the contract for [`intakt:build:clone`](build-clone.md), without a source line (whether the task's author may name one is not read here).

`Git/Infrastructure/GitBuildCheckout` implements `BuildCheckout` by calling `UpdateMirror::update()` and `MakeBuildClone::make()`; every failure of either (an invalid project or branch name, `MirrorNotUpdated`, `NetworkGitFailed`, `BuildCloneNotMade`, `RepositoryAttributesRefused`, a filesystem error) becomes one `BuildCheckoutFailed` with the same message, so Build never imports a use case of Git.

The run stops at the first step that fails, with that step's own message in `BuildTaskOutcome` (`last`, `failure`, `task`; `passedSteps()` lists the steps before it), and runs no later step. Nothing is retried here: a failure is recorded and the run ends. The steps after the clone (the build job, the hand-back check, the pre-push check, the signed push and the pull request) are later parts.

## The ledger

Each step appends one `LedgerEventKind::BuildStepEnded` (`build_step_ended`) event with the JSON payload `project`, `task` (the issue number as text), `step` (`task`, `mirror` or `clone`, a `BuildStep` case), `outcome` (`passed` or `failed`) and `failure` (the message, or null). The issue's title and body are never in the payload. A ledger that does not record an event stops the run with `BuildStepNotRecorded` before the next step. Showing these events in the dashboard's task detail and in `intakt:status` is a later part.

## The door

The command prints `<step> passed` for each step that passed, then `title <title>` (escaped, because the title is untrusted) once the task was read, and for a failing step an error block `<step> failed: <message>` and exit code 1. An issue number that is not a whole number from 1 to 999999999, a missing `--remote`, `--branch` or `--directory`, and a step the ledger did not record fail with a message and exit code 1.

`GitHubIssueReads` is the container's `IssueReads` in `dev` and `prod`; in the test environment `tests/Core/Build/services.yaml` replaces it with `RecordedIssues`, which serves the file `BUILD_RECORDING` names (a JSON list of issues in GitHub's shape), so the command runs on an install without GitHub.

Tests: `BuildTaskTest` (a failure at each step, a mirrors folder that cannot be created, a pull request of the number, a ledger that does not record, all steps passing, over a local git remote), `BuildTaskCommandTest`.
