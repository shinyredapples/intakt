<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log;

use PHPUnit\Event\Facade as EventFacade;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade as ExtensionFacade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

final class UnassertedErrorLogExtension implements Extension
{
    public function bootstrap(Configuration $configuration, ExtensionFacade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new class implements FinishedSubscriber {
            public function notify(Finished $event): void
            {
                foreach (LogRecords::takeErrors() as $record) {
                    // A subscriber cannot fail a test, so a PHPUnit warning names it and failOnWarning fails the run.
                    EventFacade::emitter()->testTriggeredPhpunitWarning($event->test(), sprintf('Unasserted %s log record: "%s"', $record['level'], $record['message']));
                }
            }
        });
    }
}
