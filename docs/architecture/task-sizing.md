# Task sizing

Plan task T48, part 1 (#4062): the readiness check and the size estimate of one new task. Splitting an oversize task and closing its umbrella are later parts and are not built yet.

## The call

`intakt:build:size <project> <issue>` runs `SizeTask`, in the Build module. It reads the open issue through `IssueReads::readOpen()`, asks `SizingVendor::size()` once, records the answer and parks the task only when the answer says it is not ready.

`SizingVendor` (`Plugin/Vendor/Contract/`) is its own interface, so the other implementations of `Vendor` need no sizing stub. `SymfonyAiSizingVendor` implements it: it resolves the standard tier, reserves a usage row that names the task, runs `SizingAgent` on a `TierRoutedPlatform`, then settles the row, or leaves it unsettled and rethrows the failure typed (`TypedProviderFailure`), as the judge does.

`SizingAgent` calls an agent with no tools and `PlatformSubscriber::RESPONSE_FORMAT => SizingVerdict::class`. `SizingPrompt` builds the messages only through `PromptAssembly`; the title and the body of the task are fenced as untrusted (`UntrustedSource::Task`).

## The verdict

`SizingVerdict` holds `ready`, `missing` (a list of `ReadinessGap`: `observable_result`, `acceptance_condition`, `single_outcome`), `size` (a `SizeTier`: `small`, `medium`, `large`, `split`), `estimated_lines` and `reason`. The Validator refuses a negative line count and an empty or overlong reason; plain code (`agreesWithItself()`) refuses a verdict that is not ready with nothing missing, a ready one that names a gap, and a repeated gap.

An answer that is not JSON, that the mapping or the Validator refuses, or that contradicts itself is no verdict: `Sizer::size()` returns `null`. A failed call (a rate limit, an unreachable endpoint) is not an answer and stays a typed provider error.

## What plain code does with it

| Verdict | Recorded | Task |
| --- | --- | --- |
| ready | `task_sized`, outcome `ready`, size and estimated lines | nothing else |
| not ready | `task_sized`, outcome `parked`, the missing items | parked with one question through `ParkedTasks` |
| none (invalid) | `task_sized`, outcome `invalid` | not parked, not split |

The `task_sized` payload holds the project, the task number as text, the outcome, the missing items, the size and the estimated lines. It never holds the title or the model's reason, because both are untrusted text. The question of a parked task is worded by `BuildQuestion::forMissingReadiness()` from the closed list of gaps, never from the model's text. The parking is the one that a builder's question and a bug task missing a part use (see [Build task](build-task.md#the-parked-task-record)).

## Tokens

The `started` row of the call carries the project and the task number (`UsageStarted::$task`), so a task's sizing cost is read from the ledger by its number. The call is settled when it answered, valid or not, and left unsettled when it failed.

## Not built here

The split of an oversize task (plan record, parts, part marker, labels, resume, never split twice, `Waits for` lines), the check of Evidence items and the validity check against main. A verdict of tier `split` is only recorded.

Tests: `SizeTaskTest`, `SizeTaskCommandTest`, `SizingASampleTaskTest` (the whole path over a scripted platform), `SizingAgentTest`, `SymfonyAiSizingVendorTest`.
