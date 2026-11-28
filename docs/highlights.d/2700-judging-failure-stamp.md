## Symfony components

| Messenger `ListableReceiverInterface` on the failure transport | `intakt:review` reads the judging failure recorded in the ledger (`judging_failed`, written by the handler of the `RecordFailedJudging` message that the judging's `DispatchOnFailureStamp` carries) or, when that message could not be dispatched, lists the messages parked in the `failed` transport with `all()`, which hands nothing out, and stops waiting as `blocked` with the reason "the judging failed" | in progress | T13 (#18, part #1857), `src/Core/Review/UseCase/JudgeRound/FailedJudgings.php` |
