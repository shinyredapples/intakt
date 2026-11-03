<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log;

use PHPUnit\Event\Facade;
use PHPUnit\Event\TestSuite\Finished;
use PHPUnit\Event\TestSuite\FinishedSubscriber;

final readonly class WarnOnErrorsAfterASuite implements FinishedSubscriber
{
    public function __construct(private LogRecords $records)
    {
    }

    // Checked when a class finishes, so an error from tearDownAfterClass is named with its own class, not blamed on the next class's first test.
    public function notify(Finished $event): void
    {
        foreach ($this->records->takeErrors() as $record) {
            Facade::emitter()->testRunnerTriggeredPhpunitWarning(sprintf('Unasserted %s log record after the tests of %s: "%s"', $record['level'], $event->testSuite()->name(), $record['message']));
        }
    }
}
