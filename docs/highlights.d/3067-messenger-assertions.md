## Symfony components

| Messenger 8.2 test assertions (`MessengerAssertionsTrait`) | the tests that read the async queue right after a dispatch or a request, before any worker runs, use `assertQueuedMessageCount()` and `getQueuedMessages()`; tests that read what was sent after a worker drained the queue keep reading `getSent()`, because the helpers read only what is still queued | done | #3067 (part #2698), `tests/Core/Dashboard/UseCase/ProjectFilterLiveUpdatesTest.php`, `tests/Core/Dashboard/UseCase/PlayDemo/PlayDemoControllerTest.php` |
