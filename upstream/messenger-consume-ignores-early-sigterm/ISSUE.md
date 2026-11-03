Repository: `symfony/symfony`. Template: Bug report.

Title: [Messenger] `messenger:consume` ignores a SIGTERM received before the worker is created

### Symfony version(s) affected

8.1.10 (the same code on the 8.1 and 8.2 branches)

### Description

`Application::doRunCommand()` registers the signal handlers before `console.command` is dispatched and before `ConsumeMessagesCommand::execute()` creates the `Worker`. When SIGTERM arrives in that window, `ConsumeMessagesCommand::handleSignal()` returns `false` because `$this->worker` is null. The application does not exit, the signal is not remembered, and the worker then runs until `--time-limit` (or a supervisor's kill timeout) ends it.

### How to reproduce

```php
$dispatcher = new EventDispatcher();
$dispatcher->addListener(ConsoleEvents::COMMAND, static fn () => posix_kill(getmypid(), SIGTERM));

$application = new Application();
$application->setDispatcher($dispatcher);
$application->setAutoExit(false);
$application->addCommand(new ConsumeMessagesCommand(
    new RoutableMessageBus(new ServiceLocator([])),
    new ServiceLocator(['memory' => static fn () => new InMemoryTransport()]),
    new EventDispatcher(),
));

// Returns after 5 s instead of stopping at once.
$application->run(new ArrayInput(['command' => 'messenger:consume', 'receivers' => ['memory'], '--time-limit' => 5]), new NullOutput());
```

### Possible Solution

Remember the signal in `handleSignal()` when there is no worker yet and stop the worker right after it is created (or return the exit code, since no message was received). The fix looks small.

### Additional Context

Seen when a container is stopped while its consumers are still booting: the supervisor waits for its kill timeout.
