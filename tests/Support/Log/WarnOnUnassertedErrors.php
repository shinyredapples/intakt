<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log;

use PHPUnit\Event\Facade as EventFacade;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;

final class WarnOnUnassertedErrors implements FinishedSubscriber
{
    public function notify(Finished $event): void
    {
        foreach (LogRecords::takeErrors() as $record) {
            // A subscriber cannot fail a test, so a PHPUnit warning names it and failOnWarning fails the run.
            EventFacade::emitter()->testTriggeredPhpunitWarning($event->test(), sprintf('Unasserted %s log record: "%s"', $record['level'], $record['message']));
        }
    }
}
