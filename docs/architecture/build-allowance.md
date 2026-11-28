# When a build over its allowance stops

Progress, not spend, ends a build: one over its allowance keeps going while it makes progress, until the runaway ceiling or the per-task total applies ([Budget and model planner](../design/05-architecture/06-budget-and-model-planner.md), "Allowance per task"). Plain code in `src/Core/Build/Domain/` decides when a running build stops and names the cause. Nothing calls it yet: the watch of a running build (#3965) will, and the ledger record of the cause and the pages follow with T60 (#1737). The limits are configured already (below).

## A stopped job keeps its commits

`RecordedBuildStops` in `src/Core/Build/UseCase/RunBuildJob/` holds the cause recorded for a job, keyed by the job's id (project and branch). `record()` stores it; `causeFor()` returns it and forgets it. In this part only tests call `record()`, and nothing stops a running job yet.

`RunBuildJob` reads the cause after the job ends, also when the job failed, and puts it on `BuildJobOutcome::$stopCause`; a job without a stop carries null. The cause is read only for a job the runner was called with and did not refuse. A job that never reached the runner leaves the cause where it is, because it belongs to the build that holds the branch's job id, whatever failed and with whatever exception: resolving the model, checking the clone folder, reading the build script, writing the prompt file, or building the spec (for example `JobInput` refusing a relative prompt folder with `JobRefused`, or the gate's `registryHosts()` throwing). A job the runner refuses (`JobRefused`) does not read it either. Once the prompt file exists, it is removed on every path, a failed write of the prompt, a refused input and a failed gate included. The verdict does not change: a stopped session bundles what it committed like a session cut at its allowance, so its bundle and pull-request text go through the hand-back check, the pre-push check and the push as usual.

## The classes

- `BuildLimits`: the allowance in tokens, the idle limit in seconds, the runaway ceiling in tokens and the per-task total in tokens across continuations. Every limit must be at least 1, and a ceiling below the allowance is refused with `BuildLimitsRefused` (an `InvalidArgumentException` in `src/Core/Build/Domain/`). The container builds it from configuration (below).
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

Tests: `tests/Core/Build/Domain/BuildStopRuleTest.php` moves a `MockClock` between looks to force each case; `tests/Core/Build/Domain/BuildLimitsTest.php` covers the refused limits; `tests/Core/Build/Infrastructure/CheckBuildLimitsPassTest.php` covers the values the pass refuses and allows, and `tests/Core/Build/UseCase/RunBuildJob/BuildLimitsConfigTest.php` boots a kernel with other limits and shows `RunBuildJob` holding them. `tests/Core/Build/UseCase/RunBuildJob/RunBuildJobTest.php` and `tests/Core/Build/UseCase/BuildTask/BuildTaskTest.php` record each cause while the job runs and show the bundle handed back and pushed.
