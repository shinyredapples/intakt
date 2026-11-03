<?php

declare(strict_types=1);

use Symfony\Component\Console\Application;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Command\ConsumeMessagesCommand;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

const TIME_LIMIT = 5;

$dispatcher = new EventDispatcher();
// The signal arrives after the handlers are registered and before the worker exists, as when a supervisor stops a consumer that is still booting.
$dispatcher->addListener(ConsoleEvents::COMMAND, static fn (): bool => posix_kill(getmypid(), SIGTERM));

$command = new ConsumeMessagesCommand(
    new RoutableMessageBus(new ServiceLocator([])),
    new ServiceLocator(['memory' => static fn (): InMemoryTransport => new InMemoryTransport()]),
    new EventDispatcher(),
);
$application = new Application();
$application->setDispatcher($dispatcher);
$application->setAutoExit(false);
$application->addCommand($command);

$started = hrtime(true);
register_shutdown_function(static function () use ($started): void {
    $seconds = (hrtime(true) - $started) / 1_000_000_000;

    if ($seconds >= TIME_LIMIT) {
        printf('SIGTERM before the worker started was ignored: the consumer ran until its time limit (%.1f s)%s', $seconds, PHP_EOL);

        exit(1);
    }

    printf('The consumer stopped after %.1f s%s', $seconds, PHP_EOL);
    echo 'REPRODUCTION PASSES', PHP_EOL;

    exit(0);
});

$application->run(new ArrayInput(['command' => 'messenger:consume', 'receivers' => ['memory'], '--time-limit' => TIME_LIMIT, '--sleep' => 0.1]), new NullOutput());
