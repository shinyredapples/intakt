# When a build over its allowance stops

Progress, not spend, ends a build: one over its allowance keeps going while it makes progress, until the runaway ceiling or the per-task total applies ([Budget and model planner](../design/05-architecture/06-budget-and-model-planner.md), "Allowance per task"). Plain code in `src/Core/Build/Domain/` decides when a running build stops and names the cause. The build job holds the configured limits and hands back a stopped job's commits ([below](#the-build-job)); nothing watches a running build yet, so no job is stopped: watching it and ending its session is #3965, the ledger record of the cause is part 2 of #3964.

## The classes

- `BuildLimits`: the allowance in tokens, the idle limit in seconds, the runaway ceiling in tokens and the per-task total in tokens across continuations. Every limit must be at least 1, and a ceiling below the allowance is refused with `InvalidArgumentException`.
- `BuildProgress`: what the caller saw at one moment: tokens used by this build, tokens used by the task across its continuations, a content fingerprint, the failing tests (failure output by test name) and whether a check is running.
- `BuildWatch`: the readonly state the caller keeps between looks: the limits, the latest `BuildProgress` and when progress was last seen. `BuildStopRule` is a shared service and keeps no state of one build.
- `BuildStopRule`: `startWatching()` begins a watch, `observe()` returns the next watch for a new `BuildProgress`, and `stopCause()` returns a `BuildStopCause`, or null to let the build go on. Time comes only from the injected `ClockInterface`.
- `BuildStopCause`: `no_progress`, `runaway_ceiling` or `per_task_total`.

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

Tests: `tests/Core/Build/Domain/BuildStopRuleTest.php` moves a `MockClock` between looks to force each case; `tests/Core/Build/Domain/BuildLimitsTest.php` covers the refused limits.

## The build job

- **Limits from configuration.** Four parameters in `config/services.yaml`, named in `BuildServices`: `intakt.build.allowance_tokens` (2,000,000), `intakt.build.idle_seconds` (900), `intakt.build.ceiling_tokens` (6,000,000, three times the allowance) and `intakt.build.task_total_tokens` (12,000,000, two full builds up to the ceiling). The defaults are the builder's choice until sizing sets an allowance per task (T60, #342). The compiler pass `Infrastructure/BuildLimitsPass` (added in `Kernel::addApplicationPasses()`) reads the four parameters before any argument is typed, refuses a value that is not an integer (a YAML `true` would otherwise reach `BuildLimits` as 1), builds a `BuildLimits` once so its own ranges are refused too, and registers the `BuildLimits` service with the four values. A wrong value is an `InvalidArgumentException` while the container compiles, so the application does not boot. `RunBuildJob` gets the service injected as `RunBuildJob::$limits`, public for the watch of #3965, its one reader. The per-task total is meant for the tokens of every continuation of the task. This change does not read them from the ledger: that read is built in #3965, by the watch that fills `BuildProgress::$taskTokensUsed`.
- **The cause on the outcome.** `BuildJobOutcomes::of()` maps the job's result to a `BuildJobOutcome` and puts the given `BuildStopCause` on it (`BuildJobOutcome::$stopCause`, null for a job without a stop). The cause never comes from the job's output, which the builder writes. The verdict stays what the output shows: a stopped job that bundled its commits and its pull request text is `Built`, so `BuildTask` sends it through the [hand-back check](build-job.md) exactly like a session cut at its allowance. `RunBuildJob` passes null until #3965 watches the job.

Tests: `tests/Core/Build/UseCase/RunBuildJob/BuildJobOutcomesTest.php` takes each cause from `BuildStopRule` on a `MockClock` and shows the bundle and text kept on the outcome; `tests/Core/Build/UseCase/BuildTask/BuildTaskTest.php` runs a committing job through `RunBuildJob`, stops it with each cause and shows the real `HandBackCheck` accepting its bundle while the outcome keeps its text; `BuildLimitsConfigTest` boots the container with the shipped setting and `RefusedBuildLimitsConfigTest` shows a refused one stopping the boot; `tests/Core/Build/Infrastructure/BuildLimitsPassTest.php` covers each refused limit and the boundaries.
