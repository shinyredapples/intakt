## Symfony components

| Messenger `ListableReceiverInterface` on the failure transport | `intakt:review` lists the messages parked in the `failed` transport with `all()`, which hands nothing out, and stops waiting as `blocked` with the reason "the judging failed" once the judging message of its complete round is there | in progress | T13 (#18, part #1857), `src/Core/Review/UseCase/JudgeRound/FailedJudgings.php` |
