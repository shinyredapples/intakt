## Symfony components

| EventDispatcher `ScopedEventDispatcher` | the tests run with `APP_DEBUG=0`, where the framework's `event_dispatcher` is a compiled dispatcher that deprecates adding a listener at runtime; the test container decorates it with `ScopedEventDispatcher`, so a test still adds a listener for its own kernel's lifetime | in progress | #1311 (part #3803), `tests/Support/services.yaml`, `phpunit.dist.xml` |
