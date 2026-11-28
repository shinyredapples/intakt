# Task sizing

Plan task T48, part 1 (#4062): the readiness check and the size estimate of one new task. Splitting an oversize task and closing its umbrella are later parts and are not built yet.

## The call

`intakt:build:size <project> <issue>` runs `SizeTask`, in the Build module. It takes the `LockFactory` lock `SizingRecords::keyOf(project, task)` (a length-prefixed hash of project and number) without waiting and with no TTL; a run that finds it held fails with "another sizing of it is running" and makes no call. Under the lock it reads the task, looks for an earlier verdict, asks `SizingVendor::size()` once, parks and comments when the answer says the task is not ready, and records the answer.

`EligibleTasks` reads the open issue through `IssueReads::readOpen()` and applies intake's rule before any call: the issue carries the project's eligibility label (`EligibilityLabels`), its author has write access (`SteeringAccounts`), its history (`IssueHistories`, as of the read's update time) holds the same body and a last editor with write access, and the label was applied by an account with write access. A refused or unreadable issue fails the command with the reason and spends no tokens.

`SizingVendor` (`Plugin/Vendor/Contract/`) is its own interface, so the other implementations of `Vendor` need no sizing stub. `SymfonyAiSizingVendor` implements it: it resolves the standard tier for `SizingRequest::$project`, reserves a usage row that names the task, runs `SizingAgent` on a `TierRoutedPlatform`, then settles the row, or leaves it unsettled and rethrows the failure typed (`TypedProviderFailure`), as the judge does.

`SizingAgent` calls an agent with no tools and `PlatformSubscriber::RESPONSE_FORMAT => SizingVerdict::class`. `SizingPrompt` builds the messages only through `PromptAssembly`; the title and the body of the task are fenced as untrusted (`UntrustedSource::Task`). The prompt asks for the size the task earns by itself, never a decision to split: the lower split boundary of a small pool is for plain code to apply in the split part.

## The verdict

`SizingVerdict` holds `ready`, `missing` (a list of `ReadinessGap`: `observable_result`, `acceptance_condition`, `single_outcome`), `size` (a `SizeTier`: `small`, `medium`, `large`, `split`), `estimated_lines` and `reason`. The Validator refuses a negative line count and an empty or overlong reason; plain code (`agreesWithItself()`) refuses a verdict that is not ready with nothing missing, a ready one that names a gap, and a repeated gap. `SizingAgent` also compares the answer's JSON keys with the constructor's parameters, because the mapping drops a field it does not know: an extra or a missing field is no verdict.

An answer that is not JSON, that the mapping or the Validator refuses, or that contradicts itself is no verdict: `Sizer::size()` returns `null`. A failed call (a rate limit, an unreachable endpoint) is not an answer and stays a typed provider error.

## What plain code does with it

| Verdict | Task | Recorded after |
| --- | --- | --- |
| ready | nothing else | `task_sized`, outcome `ready`, size and estimated lines |
| not ready | parked with one question through `ParkedTasks`, then one issue comment through `TaskComments` | `task_sized`, outcome `parked`, the missing items |
| none (invalid) | not parked, not split, no comment | `task_sized`, outcome `invalid` |

The question and the comment are worded by `BuildQuestion::forMissingReadiness()` from the closed list of gaps, never from the model's text; the comment is `asComment()`: the question and its choices. The parking is the one that a builder's question and a bug task missing a part use (see [Build task](build-task.md#the-parked-task-record)). `TaskComments` (`Plugin/GitHubForge/Contract/`) is implemented by `GitHubTaskComments`, which posts through `GitHubIssueComments::post()`.

`task_sized` is appended last, with the lock's key as its subject, so `parked` means parked and asked. A failed park or comment fails the command and records no `task_sized`; a retry parks the task again in place and comments once. The payload holds the project, the task number as text, the outcome, the missing items, the size, the estimated lines and `text`, a hash of the title and body. It never holds the title, the body or the model's reason, because they are untrusted text.

## One verdict per text

Before the call, `SizingRecords::holdsAVerdictOn()` reads the `task_sized` lines of that subject. One with the same `text` hash and outcome `ready` or `parked` ends the run with outcome `already_sized` and no call. An `invalid` line does not count, and an edited title or body is sized again.

## Tokens

The `started` row of the call carries the project and the task number (`UsageStarted::$task`), so a task's sizing cost is read from the ledger by its number. The call is settled when it answered, valid or not, and left unsettled when it failed.

## Not built here

The split of an oversize task (plan record, parts, part marker, labels, resume, never split twice, `Waits for` lines, the lower split tier of a small pool), the check of Evidence items and the validity check against main. A verdict of tier `split` is only recorded.

Tests: `SizeTaskTest` (overlap forced by a re-entrant run inside the vendor call, failed park and comment, repeat sizing), `EligibleTasksTest`, `SizeTaskCommandTest`, `SizingASampleTaskTest` (the whole path over a scripted platform), `SizingAgentTest`, `SymfonyAiSizingVendorTest`, `GitHubTaskCommentsTest`.
