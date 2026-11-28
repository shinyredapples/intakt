# When a build over its allowance stops

Progress, not spend, ends a build: one over its allowance keeps going while it makes progress, until the runaway ceiling or the per-task total applies ([Budget and model planner](../design/05-architecture/06-budget-and-model-planner.md), "Allowance per task"). Plain code in `src/Core/Build/Domain/` decides when a running build stops and names the cause. `RunBuildJob` watches a running build with it and ends the session on a stop ([The watch](#the-watch)). The limits are configured, and a recorded cause reaches the ledger (below).

## A stopped job keeps its commits

`RecordedBuildStops` in `src/Core/Build/UseCase/RunBuildJob/` holds the cause recorded for a job, keyed by the job's id (project and branch). `record()` stores it; `causeFor()` returns it and forgets it. `BuildSessionWatch` calls `record()` on the first stop of a running job.

`RunBuildJob` reads the cause after the job ends, also when the job failed, and puts it on `BuildJobOutcome::$stopCause`; a job without a stop carries null. When the runner was called and then throws `JobEventNotRecorded` or `DockerEngineFailed` (a job-end failure), `RunBuildJob` rethrows it as `BuildJobEndFailed` (in the same folder, a `ReportableFailure`), which keeps the original's message unchanged, holds the original as its previous exception and carries the cause read for the job on `$stopCause`, so the cause is still read once and the branch's next build never inherits it. Any other exception the runner throws still reads and forgets the cause and passes on as it is. The cause is read only for a job the runner was called with and did not refuse. A job that never reached the runner leaves the cause where it is, because it belongs to the build that holds the branch's job id, whatever failed and with whatever exception: resolving the model, checking the clone folder, reading the build script, writing the prompt file, or building the spec (for example `JobInput` refusing a relative prompt folder with `JobRefused`, or the gate's `registryHosts()` throwing). A job the runner refuses (`JobRefused`) does not read it either. Once the prompt file exists, it is removed on every path, a failed write of the prompt, a refused input and a failed gate included; after a job-end failure the prompt's removal runs before the rethrow and its own failure is dropped, so it cannot replace the failure and its cause. A `DockerEngineFailed` from the gate before the runner was called is not wrapped and reads no cause. The verdict does not change: a stopped session bundles what it committed like a session cut at its allowance, so its bundle and pull-request text go through the hand-back check, the pre-push check and the push as usual.

## The ledger names the cause

`BuildTask` writes the cause into the `stop_cause` key of the `build_job` step's `build_step_ended` row, as the `BuildStopCause` value (`no_progress`, `runaway_ceiling` or `per_task_total`), through `UseCase/BuildTask/EndedStep`. The row names it whether the step passed, failed or asked: the `asked` row that `ParkedTasks::park()` writes for a stopped job that ends with a question records its cause, and a stopped job that handed back no commit, whose earlier parked question could not be cleared, or whose parking the database refused (the failed row of a refused parking) still records its cause beside the failure. A stopped job whose end failed (`BuildJobEndFailed`, from `JobEventNotRecorded` or `DockerEngineFailed` after the runner was called) records its cause in the failed row too, beside the same failure text as before. Every other row, and the row of a job that was not stopped, holds `stop_cause: null`; rows written before this key existed lack it, and no reader reads it yet ([Build task, The ledger](build-task.md#the-ledger)).

## The classes

- `BuildLimits`: the allowance in tokens, the idle limit in seconds, the runaway ceiling in tokens and the per-task total in tokens across continuations. Every limit must be at least 1, and a ceiling below the allowance is refused with `BuildLimitsRefused` (an `InvalidArgumentException` in `src/Core/Build/Domain/`). The container builds it from configuration (below).
- `BuildProgress`: what the caller saw at one moment: tokens used by this build, tokens used by the task across its continuations, a content fingerprint, the failing tests (failure output by test name) and whether a check is running.
- `BuildWatch`: the readonly state the caller keeps between looks: the limits, the latest `BuildProgress` and when progress was last seen. `BuildStopRule` is a shared service and keeps no state of one build.
- `BuildStopRule`: `startWatching()` begins a watch, `observe()` returns the next watch for a new `BuildProgress`, and `stopCause()` returns a `BuildStopCause`, or null to let the build go on. Time comes only from the injected `ClockInterface`.
- `BuildStopCause`: `no_progress`, `runaway_ceiling` or `per_task_total`.

## The configured limits

The four limits are container parameters with defaults in `config/services.yaml`, named in `BuildServices`: `ALLOWANCE_TOKENS` (`intakt.build.allowance_tokens`, 5000000), `IDLE_SECONDS` (`intakt.build.idle_seconds`, 600), `CEILING_TOKENS` (`intakt.build.ceiling_tokens`, 20000000) and `TASK_TOTAL_TOKENS` (`intakt.build.task_total_tokens`, 50000000). No sizing sets them yet, so the defaults are starting points to tune from recorded stops.

`CheckBuildLimitsPass` in `src/Core/Build/Infrastructure/`, registered by `Kernel::addApplicationPasses()`, checks them when the container is built. It reads each parameter raw and refuses any value that is not a PHP `int` with `InvalidArgumentException` naming the parameter. That also refuses an `%env(int:...)%` value or a `%parameter%` reference, which are still strings at that point and would otherwise be known only when a build starts. It then builds a `BuildLimits` from the four, so the domain rules apply unchanged, and catches only their `BuildLimitsRefused`, rethrown as an `InvalidArgumentException` that names the parameter section and keeps the refusal as its cause.

`config/services.yaml` defines the `BuildLimits` service from the four parameters, and `RunBuildJob` receives it as the readonly `$limits`. `RunBuildJob` hands it to the watch. The per-task total counts the session's own tokens only, because no earlier continuation's tokens are on the ledger yet (a gap).

## The watch

`RunBuildJob` passes a `BuildSessionWatch` (`src/Core/Build/UseCase/RunBuildJob/`) to `JobRunner::run()`, whose second parameter is an optional `JobWatch` (`Core/Job/Contract/`). `JobContainers` then waits for the container in slices of the watch's interval (30 seconds) instead of one long wait. After each slice it reads what the job wrote to its output folder so far (`JobOutputDirectories::peek()`: plain files only, within the usual size bounds, never refused) and asks `endsSession()`. On the first yes it sends `USR1` to the job's first process once (`DockerEngine::endSession()`, the engine's kill call with that signal) and goes on waiting for the job's own exit; the run time's `SIGKILL` stays the backstop.

`docker/job/build.sh` traps `USR1` before anything starts: it kills the session's process group, remembers the stop in a shell variable (never a file, because the builder can write files), waits for the session again (a trapped signal ends `wait` early), and goes on to log `claude stopped on the progress rule, its commits are kept` and bundle, as after the allowance's cut. So a stop never ends the job script. A build that sends `USR1` itself only ends its own session.

The watch reads the CLI's `stream-json` events in `build.log` (`BuildLogReading`; the script now starts the CLI with `--output-format stream-json --verbose`) and feeds `BuildStopRule` a `BuildProgress`:

- Tokens: the sum of every message's usage (input, output and both cache counts, each message once by its id), or the final result's usage when larger. The watch keeps the highest figure it has seen, so a lower figure planted in the log never lowers it.
- Content change: a fingerprint of the whitespace-stripped input of each `Edit`, `Write` and `MultiEdit` call that changes text, and of each `git commit` command without `--allow-empty`.
- Failing tests: the `N) Class::method` names in the result of the last `phpunit`, `bin/check` or `composer test` call.
- Check running: such a call has no result yet (a long run is progress, never idle time).

Gaps, because the builder works in a clone inside the container that the host cannot read while the job runs: commits are seen through the CLI's calls, not the clone, so an edit counts as progress before it is committed and a commit made by a script is not seen; failing tests are read from the log, so a runner of another format shows none. The log is the builder's to write, so every figure from it can only make the build look more active or idle, and the ceiling and the per-task total rest on the token figure, which the CLI reports and the watch only raises; the job's own run time ends a build whatever the log says.

Tests: `tests/Core/Build/UseCase/RunBuildJob/WatchedBuildJobTest.php` ticks the watch through the build job with a scripted runner and a `MockClock`, one test for each stop kind (no progress, runaway ceiling, per-task total), each showing the bundle kept and the cause recorded, and one for a build over its allowance that keeps making progress; `BuildLogReadingTest.php` covers the reading; `tests/Core/Job/ContainerJobRunnerTest.php` covers the signal sent once; `tests/Core/Build/BuildScriptTest.php` covers `USR1` ending the session with the bundle kept.

## What counts as progress

Each of these resets the idle time:

1. The content fingerprint changed. The caller computes it so that whitespace does not count, so an empty or whitespace-only commit leaves it as it was.
2. The set of failing test names changed. The names are compared as a sorted set, so a failure of the same test with different output is not progress.
3. A check is running, or was running at the previous look: a long test run is never idle time.

## The order

`stopCause()` checks in this order and returns the first that applies:

1. The task's tokens across continuations reached the per-task total: `per_task_total`.
2. This build's tokens reached the runaway ceiling: `runaway_ceiling`.
3. The build is under its allowance, or a check is running: it goes on.
4. No progress for the idle limit or longer: `no_progress`.

The ceiling and the per-task total stop a build whatever its progress, also while a check runs. A build under its allowance is never stopped for lack of progress.

Tests: `tests/Core/Build/Domain/BuildStopRuleTest.php` moves a `MockClock` between looks to force each case; `tests/Core/Build/Domain/BuildLimitsTest.php` covers the refused limits; `tests/Core/Build/Infrastructure/CheckBuildLimitsPassTest.php` covers the values the pass refuses and allows, and `tests/Core/Build/UseCase/RunBuildJob/BuildLimitsConfigTest.php` boots a kernel with other limits and shows `RunBuildJob` holding them. `tests/Core/Build/UseCase/RunBuildJob/RunBuildJobTest.php` and `tests/Core/Build/UseCase/BuildTask/BuildTaskTest.php` record each cause while the job runs and show the bundle handed back and pushed; `BuildTaskTest` also shows each cause in the `build_job` row (passed, and failed with no commit or an uncleared question; the uncleared-question test covers `per_task_total` only, and failed by a job-end failure, `JobEventNotRecorded` or `DockerEngineFailed`) and `stop_cause` null in every row of a run without a stop and in the failed row of an unstopped job-end failure; `RunBuildJobTest` also shows a `DockerEngineFailed` from the gate leaving the branch's cause, a failed prompt removal not hiding the job-end failure and its cause, and `BuildJobEndFailed` carrying the cause, the original message and the original failure, and the branch's next build after it carrying no cause; `tests/Core/Build/UseCase/BuildTask/BuildTaskParksOnSqliteTest.php` shows each cause in the failed row of a job with no commit on the install's database, `runaway_ceiling` in the failed row of a job whose end event was not recorded, and for `no_progress` the cause in the `asked` row of a stopped job that asks a question and in the failed row of one whose question cannot be stored (a refused parking: that case runs on SQLite only, because its trigger is written in SQLite, and is skipped on the other engines); and `tests/Core/Build/UseCase/BuildTask/BuildRunTest.php` shows the payload naming each cause by its value.
