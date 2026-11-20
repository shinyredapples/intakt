# Watchdog over main

`Core/Watchdog` (issue #2969, part 1 of #2914) classifies main's new commits. This part holds the rules only: the contract types in `src/Core/Watchdog/Contract/` and the classifier in `src/Core/Watchdog/Domain/`. Nothing calls it yet: reading main with git (part 2), the console command, the project halt and the owner card come in later issues. The module has no container wiring, so `WatchdogServices` holds no ids yet.

## Rules

`CommitClassifier::classify(WatchedMain)` returns a `Classification`: clean, or a list of `MainViolation`s, each naming a commit and its `Violation`.

| Commit | Outcome |
| --- | --- |
| main's history no longer contains the last seen head (a force push rewrote it) | violation `history_rewritten`, named on the last seen head; no other commit is judged |
| a commit on the first-parent walk that is the head of a recorded merge | passes, before any signature is read |
| any other commit whose signature status is `owner_signed` (a key the owner registered) | passes |
| any other commit whose signature status is `unsigned` | violation `unsigned` |
| any other commit whose signature status is `unknown_key` (signed by a key the owner did not register) | violation `unknown_key` |

Every violating commit of the walk is listed, oldest first.

## Types

- `WatchedMain(lastSeenHead, list<RecordedMerge>)`: the head the watchdog saw last and the merges Intakt recorded. `isRecordedHead()` matches a commit against the recorded heads only, never the bases.
- `RecordedMerge(head, base)`: both must be full commit names (40 lowercase hexadecimal characters for SHA-1, 64 for SHA-256); anything else, an abbreviated name or a ref name included, throws `CommitNameNotFull`. `WatchedMain` checks its last seen head the same way (`CommitName::full()`).
- `SignatureStatus`: a string-backed enum, `owner_signed`, `unsigned`, `unknown_key`.
- `Violation`: a string-backed enum, `unsigned`, `unknown_key`, `history_rewritten`.

## The history port

The classifier reads main only through `Contract/CommitHistory`, so its rules are tested on forced histories with a stub:
- `contains(commit)`: whether main still holds the commit; false after a force push dropped it.
- `firstParentsSince(commit)`: main's first-parent commits after it, oldest first.
- `signatureOf(commit)`: the commit's `SignatureStatus`.

No implementation exists yet; part 2 reads main with `HardenedGit`.

Tests: `tests/Core/Watchdog/Domain/CommitClassifierTest.php` (a recorded merge is clean, a recorded head passes even when unsigned, an owner-signed commit passes, an unsigned commit and a foreign key are violations, a force push is `history_rewritten`), `tests/Core/Watchdog/Contract/RecordedMergeTest.php` and `tests/Core/Watchdog/Contract/WatchedMainTest.php`.
