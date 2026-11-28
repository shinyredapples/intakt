# When a build over its allowance stops

Progress, not spend, ends a build: one over its allowance keeps going while it makes progress, until the runaway ceiling or the per-task total applies ([Budget and model planner](../design/05-architecture/06-budget-and-model-planner.md), "Allowance per task"). Plain code in `src/Core/Build/Domain/` decides when a running build stops and names the cause. Nothing calls it yet: the watch of a running build (#3965) will, and the pages follow with T60 (#1737). What that watch will feed it is already read from each ended build job (below). The limits are configured already, and a recorded cause reaches the ledger (below).

## A stopped job keeps its commits

`RecordedBuildStops` in `src/Core/Build/UseCase/RunBuildJob/` holds the cause recorded for a job, keyed by the job's id (project and branch). `record()` stores it; `causeFor()` returns it and forgets it. In this part only tests call `record()`, and nothing stops a running job yet.

`RunBuildJob` reads the cause after the job ends, also when the job failed, and puts it on `BuildJobOutcome::$stopCause`; a job without a stop carries null. When the runner was called and then throws `JobEventNotRecorded` or `DockerEngineFailed` (a job-end failure), `RunBuildJob` rethrows it as `BuildJobEndFailed` (in the same folder, a `ReportableFailure`), which keeps the original's message unchanged, holds the original as its previous exception and carries the cause read for the job on `$stopCause`, so the cause is still read once and the branch's next build never inherits it. Any other exception the runner throws still reads and forgets the cause and passes on as it is. The cause is read only for a job the runner was called with and did not refuse. A job that never reached the runner leaves the cause where it is, because it belongs to the build that holds the branch's job id, whatever failed and with whatever exception: resolving the model, checking the clone folder, reading the build script, writing the prompt file, or building the spec (for example `JobInput` refusing a relative prompt folder with `JobRefused`, or the gate's `registryHosts()` throwing). A job the runner refuses (`JobRefused`) does not read it either. Once the prompt file exists, it is removed on every path, a failed write of the prompt, a refused input and a failed gate included; after a job-end failure the prompt's removal runs before the rethrow and its own failure is dropped, so it cannot replace the failure and its cause. A `DockerEngineFailed` from the gate before the runner was called is not wrapped and reads no cause. The verdict does not change: a stopped session bundles what it committed like a session cut at its allowance, so its bundle and pull-request text go through the hand-back check, the pre-push check and the push as usual.

## What a build job shows

`BuildJobObserver` in `src/Core/Build/UseCase/RunBuildJob/` turns the output of an ended job into a `BuildObservation`, and `RunBuildJob` puts it on `BuildJobOutcome::$observation` (null when the runner refused the output). `intakt:build:run -v` prints it. Nothing feeds it to `BuildStopRule` yet: the watch (#3965) will.

The observation keeps two parts apart, so a figure the builder can forge never reaches the runaway ceiling or the per-task total:

1. `TrustedBuildFigures`: the tokens of the Claude Code CLI's report in `build.log` and the time from the injected `ClockInterface`. Each line of the log that decodes to a `result` event with whole counts of zero or more is read with the Claude Code bridge's `TokenUsageExtractor`; its input, output, cache creation and cache read tokens are added up, and the largest report counts, so a lower report the builder appends cannot lower the figure. A sum past `PHP_INT_MAX` counts as `PHP_INT_MAX`.
2. `UntrustedBuildFigures`: a fingerprint of the branch tips the bundle's header names (empty without a bundle or tips), the failing tests and whether a check runs.

`BuildObservation::progress()` gives the `BuildProgress` for `BuildStopRule`, both token figures from the trusted part; the task's figure is this build's alone until the continuations' tokens are read from the ledger.

The gaps: `JobRunner::run()` returns only once the job has ended, so every figure is read then. The session's failing tests and running checks never reach the job's output, so the observation reports none and no check running. The fingerprint changes with any new tip, also for a commit that changes no content, because reading the content needs git on the bundle, which the hand-back check runs later. A builder that replaces `build.log` with a file of its own can hide the CLI's report, because the CLI then writes to the file it opened.

## The ledger names the cause

`BuildTask` writes the cause into the `stop_cause` key of the `build_job` step's `build_step_ended` row, as the `BuildStopCause` value (`no_progress`, `runaway_ceiling` or `per_task_total`), through `UseCase/BuildTask/EndedStep`. The row names it whether the step passed, failed or asked: the `asked` row that `ParkedTasks::park()` writes for a stopped job that ends with a question records its cause, and a stopped job that handed back no commit, whose earlier parked question could not be cleared, or whose parking the database refused (the failed row of a refused parking) still records its cause beside the failure. A stopped job whose end failed (`BuildJobEndFailed`, from `JobEventNotRecorded` or `DockerEngineFailed` after the runner was called) records its cause in the failed row too, beside the same failure text as before. Every other row, and the row of a job that was not stopped, holds `stop_cause: null`; rows written before this key existed lack it, and no reader reads it yet ([Build task, The ledger](build-task.md#the-ledger)).

## The classes

- `BuildLimits`: the allowance in tokens, the idle limit in seconds, the runaway ceiling in tokens and the per-task total in tokens across continuations. Every limit must be at least 1, and a ceiling below the allowance is refused with `BuildLimitsRefused` (an `InvalidArgumentException` in `src/Core/Build/Domain/`). The container builds it from configuration (below).
- `BuildObservation`, `TrustedBuildFigures` and `UntrustedBuildFigures`: what a build job showed, its trusted and untrusted figures apart ([What a build job shows](#what-a-build-job-shows)).
- `BuildProgress`: what the caller saw at one moment: tokens used by this build, tokens used by the task across its continuations, a content fingerprint, the failing tests (failure output by test name) and whether a check is running.
- `BuildWatch`: the readonly state the caller keeps between looks: the limits, the latest `BuildProgress` and when progress was last seen. `BuildStopRule` is a shared service and keeps no state of one build.
- `BuildStopRule`: `startWatching()` begins a watch, `observe()` returns the next watch for a new `BuildProgress`, and `stopCause()` returns a `BuildStopCause`, or null to let the build go on. Time comes only from the injected `ClockInterface`.
- `BuildStopCause`: `no_progress`, `runaway_ceiling` or `per_task_total`.

## The configured limits

The four limits are container parameters with defaults in `config/services.yaml`, named in `BuildServices`: `ALLOWANCE_TOKENS` (`intakt.build.allowance_tokens`, 5000000), `IDLE_SECONDS` (`intakt.build.idle_seconds`, 600), `CEILING_TOKENS` (`intakt.build.ceiling_tokens`, 20000000) and `TASK_TOTAL_TOKENS` (`intakt.build.task_total_tokens`, 50000000). No sizing sets them yet, so the defaults are starting points to tune from recorded stops.

`CheckBuildLimitsPass` in `src/Core/Build/Infrastructure/`, registered by `Kernel::addApplicationPasses()`, checks them when the container is built. It reads each parameter raw and refuses any value that is not a PHP `int` with `InvalidArgumentException` naming the parameter. That also refuses an `%env(int:...)%` value or a `%parameter%` reference, which are still strings at that point and would otherwise be known only when a build starts. It then builds a `BuildLimits` from the four, so the domain rules apply unchanged, and catches only their `BuildLimitsRefused`, rethrown as an `InvalidArgumentException` that names the parameter section and keeps the refusal as its cause.

`config/services.yaml` defines the `BuildLimits` service from the four parameters, and `RunBuildJob` receives it as the readonly `$limits`. Nothing reads it yet; the watch (#3965) will. The per-task total will count the tokens of every continuation of the task, read from the ledger, once that watch exists.

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

Tests: `tests/Core/Build/Domain/BuildStopRuleTest.php` moves a `MockClock` between looks to force each case; `tests/Core/Build/UseCase/RunBuildJob/BuildJobObserverTest.php` covers each figure with a `MockClock` (the summed report, no report, a forged lower report, an event that is no result, counts that are no whole number, a sum past `PHP_INT_MAX`, the clock's time, no bundle, a new tip, the same tips in another pack, a bundle without tips, failure lines in the log, no check running); `tests/Core/Build/Domain/BuildObservationTest.php` shows both token figures taken from the trusted part, and forged progress in the untrusted part still stopped at the ceiling and the per-task total; `RunBuildJobTest` shows the observation on an ended job's outcome and none for a refused output, and `RunBuildJobCommandTest` the two lines with `-v` and none without; `tests/Core/Build/Domain/BuildLimitsTest.php` covers the refused limits; `tests/Core/Build/Infrastructure/CheckBuildLimitsPassTest.php` covers the values the pass refuses and allows, and `tests/Core/Build/UseCase/RunBuildJob/BuildLimitsConfigTest.php` boots a kernel with other limits and shows `RunBuildJob` holding them. `tests/Core/Build/UseCase/RunBuildJob/RunBuildJobTest.php` and `tests/Core/Build/UseCase/BuildTask/BuildTaskTest.php` record each cause while the job runs and show the bundle handed back and pushed; `BuildTaskTest` also shows each cause in the `build_job` row (passed, and failed with no commit or an uncleared question; the uncleared-question test covers `per_task_total` only, and failed by a job-end failure, `JobEventNotRecorded` or `DockerEngineFailed`) and `stop_cause` null in every row of a run without a stop and in the failed row of an unstopped job-end failure; `RunBuildJobTest` also shows a `DockerEngineFailed` from the gate leaving the branch's cause, a failed prompt removal not hiding the job-end failure and its cause, and `BuildJobEndFailed` carrying the cause, the original message and the original failure, and the branch's next build after it carrying no cause; `tests/Core/Build/UseCase/BuildTask/BuildTaskParksOnSqliteTest.php` shows each cause in the failed row of a job with no commit on the install's database, `runaway_ceiling` in the failed row of a job whose end event was not recorded, and for `no_progress` the cause in the `asked` row of a stopped job that asks a question and in the failed row of one whose question cannot be stored (a refused parking: that case runs on SQLite only, because its trigger is written in SQLite, and is skipped on the other engines); and `tests/Core/Build/UseCase/BuildTask/BuildRunTest.php` shows the payload naming each cause by its value.
