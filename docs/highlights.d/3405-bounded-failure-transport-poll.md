## Symfony components

| Messenger `ListableReceiverInterface::all($limit)` on the failure transport | each poll of one round by `intakt:review` and the review page lists at most 500 parked messages with `all(500)`, so parked publications do not make every poll read the whole transport; the recurring P1 settlement, which runs once per judging and has no upper wait, still lists the whole transport with `all()` | in progress | #3405 (part 1 of #1969), `src/Core/Review/UseCase/JudgeRound/FailedJudgings.php` |
