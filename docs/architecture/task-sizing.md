# Task sizing

Plan task T48, part 1 (#4062): the readiness check and the size estimate of one new task. Splitting an oversize task and closing its umbrella are later parts and are not built yet.

## The call

`intakt:build:size <project> <issue>` runs `SizeTask`, in the Build module. It takes the `LockFactory` lock `SizingRecords::keyOf(project, task)` (a length-prefixed hash of project and number) without waiting and with no TTL; a run that finds it held fails with "another sizing of it is running" and makes no call. Under the lock it reads the task and looks for the latest verdict (a `parked` one on the same text only has its comment ensured). Otherwise it asks `SizingVendor::size()` once; when the answer says the task is not ready it parks it, records the answer and comments; when it says ready it removes an earlier readiness question and records the answer; an invalid answer is only recorded.

`EligibleTasks` reads the open issue through `IssueReads::readOpen()` and applies intake's rule before any call: the issue carries the project's eligibility label (`EligibilityLabels`) and not the `idea` label (`WorkLabels::IDEA`), its author has write access (`SteeringAccounts`), its history (`IssueHistories`, as of the read's update time) holds the same body, the same set of labels and a last editor with write access, and the label was applied by an account with write access. The label set is compared because the history lists every application of a label, also of one removed since. A refused or unreadable issue fails the command with the reason and spends no tokens.

`SizingVendor` (`Plugin/Vendor/Contract/`) is its own interface, so the other implementations of `Vendor` need no sizing stub. `SymfonyAiSizingVendor` implements it: it resolves the standard tier for `SizingRequest::$project`, reserves a usage row that names the task, runs `SizingAgent` on a `TierRoutedPlatform`, then settles the row, or leaves it unsettled and rethrows the failure typed (`TypedProviderFailure`), as the judge does.

`SizingAgent` calls an agent with no tools and `PlatformSubscriber::RESPONSE_FORMAT => SizingVerdict::class`. `SizingPrompt` builds the messages only through `PromptAssembly`; the title and the body of the task are fenced as untrusted (`UntrustedSource::Task`). The prompt asks for the size the task earns by itself, never a decision to split: the lower split boundary of a small pool is for plain code to apply in the split part.

## The verdict

`SizingVerdict` holds `ready`, `missing` (a list of `ReadinessGap`: `observable_result`, `acceptance_condition`, `single_outcome`), `size` (a `SizeTier`: `small`, `medium`, `large`, `split`), `estimated_lines` and `reason`. The Validator refuses a negative line count and an empty or overlong reason; plain code (`agreesWithItself()`) refuses a verdict that is not ready with nothing missing, a ready one that names a gap, and a repeated gap. `SizingAgent` also checks the answer's JSON text against the constructor's parameters, because the mapping drops a field it does not know, keeps the last of a field written twice and takes an object for the list: an extra or a missing field, a field written twice (the field names in the text are counted, nested ones too), and a `missing` that is not a JSON list are no verdict.

An answer that is not JSON, that the mapping or the Validator refuses, or that contradicts itself is no verdict: `Sizer::size()` returns `null`. A failed call (a rate limit, an unreachable endpoint) is not an answer and stays a typed provider error.

## What plain code does with it

| Verdict | Task | Recorded after |
| --- | --- | --- |
| ready | the readiness question of the latest verdict, if that one parked the task, is removed through `ParkedTasks::clear()` | `task_sized`, outcome `ready`, size and estimated lines |
| not ready | parked with one question through `ParkedTasks`, then one issue comment through `TaskComments::postOnce()` | `task_sized`, outcome `parked`, the missing items (after the park, before the comment) |
| none (invalid) | not parked, not split, no comment, an earlier question stays | `task_sized`, outcome `invalid` |

The question and the comment are worded by `BuildQuestion::forMissingReadiness()` from the closed list of gaps, never from the model's text; the comment is `asComment()`: the question and its choices. The parking is the one that a builder's question and a bug task missing a part use (see [Build task](build-task.md#the-parked-task-record)).

A ready verdict clears the question as the run that parked it (the `run` of the latest `parked` line), so a question a build run stored later stays. A question that cannot be removed fails the command and records no `task_sized`.

## One comment per text

`TaskComments::postOnce()` (`Plugin/GitHubForge/Contract/`) takes a `KeyedComment` (the text and a key); `SizeTask` passes `sizing-` and the `text` hash of the record as the key. `GitHubTaskComments` first reads every comment of the issue through `GitHubIssueComments::of()`. When a comment posted through this App (`IssueComment::$byThisApp`, which GitHub alone sets) holds the line `<!-- intakt:once <key> -->`, nothing is posted. Otherwise it posts the comment with that line appended through `GitHubIssueComments::post()`. A read that fails posts nothing and fails the command. A key with other characters than lowercase letters, digits and hyphens is refused before any request.

The read comes first because GitHub can store a comment and still answer the post as failed (a 502, or an answer the forge code cannot confirm). The `parked` verdict is already on record by then, so the retry on the same text makes no model call: it words the comment again from the recorded missing items, calls `postOnce()` with the same key, finds the stored comment by its marker and posts nothing, or posts the comment when the failed run did not store it. It records nothing new and ends with `already_sized`; a failed read or post fails it as before. The issue holds one comment, and the parked question and the comment name the first verdict's items. The match is on the marker and not on the wording, so an edited task that still lacks the same item gets a comment of its own.

## The record

`task_sized` has the lock's key as its subject. For a `parked` verdict it is appended right after the park and before the comment, so `parked` means the task is parked, and the comment is ensured on every run on the same text. For `ready` and `invalid` it is appended last, so `ready` means the earlier readiness question is gone. A failed park fails the command and records no `task_sized`; a failed comment fails the command after the `parked` record. The payload holds the project, the task number as text, the outcome, the missing items, the size, the estimated lines, `text` (a hash of the title and body) and `run` (the id the sizing parked the task under). It never holds the title, the body or the model's reason, because they are untrusted text.

## One verdict per text

Before the call, `SizingRecords::latestVerdictOf()` reads the `task_sized` lines of that subject and keeps the last one with outcome `ready` or `parked` (`RecordedSizing`; a `parked` line whose missing items are not all known gaps does not count). When its `text` hash is the task's, the run ends with outcome `already_sized` and no call; a `parked` one first ensures its comment (see [One comment per text](#one-comment-per-text)). Only the latest verdict counts: a task whose text was sized ready, edited into a text that was parked and then restored is sized again, so the restored text releases the parked question. An `invalid` line does not count, and an edited title or body is sized again.

## Tokens

The `started` row of the call carries the project and the task number (`UsageStarted::$task`), so a task's sizing cost is read from the ledger by its number. The call is settled when it answered, valid or not, and left unsettled when it failed.

## Not built here

The split of an oversize task (plan record, parts, labels, resume, never split twice, `Waits for` lines, the lower split tier of a small pool), the check of Evidence items and the validity check against main. A verdict of tier `split` is only recorded. The forge already files one part once by its marker ([Filing split parts](forge-contract.md#filing-split-parts)), but nothing calls it yet.

Tests: `SizeTaskTest` (overlap forced by a re-entrant run inside the vendor call, failed park and comment, a comment stored but answered with 502 and its retry, an edit that makes the task ready, repeat sizing), `SizingRetryTest` (a retry after a comment stored but answered with 502 asks no second model call, whatever that call would answer), `EligibleTasksTest`, `SizeTaskCommandTest`, `SizingASampleTaskTest` (the whole path over a scripted platform), `SizingAgentTest`, `SymfonyAiSizingVendorTest`, `GitHubTaskCommentsTest`.
