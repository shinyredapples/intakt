## Symfony components

| Messenger 8.2 worker option `stop_when_empty` | the tests that run a real `Worker` until the queue is drained pass the option to `Worker::run()` instead of a stop listener (`StopWorkerOnMessageLimitListener` or an idle `WorkerRunningEvent` listener) | done | #3067 (part #2698), `tests/Core/Review/UseCase/RunReviewRound/FailedMessageTest.php`, `tests/Application/DemoReviewLogTest.php` |
