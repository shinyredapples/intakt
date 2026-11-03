<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log;

use PHPUnit\Event\Facade;
use PHPUnit\Event\TestRunner\Finished;
use PHPUnit\Event\TestRunner\FinishedSubscriber;

final readonly class WarnOnErrorsAfterTheRun implements FinishedSubscriber
{
    public function __construct(private LogRecords $records)
    {
    }

    public function notify(Finished $event): void
    {
        foreach ($this->records->takeErrors() as $record) {
            Facade::emitter()->testRunnerTriggeredPhpunitWarning(sprintf('Unasserted %s log record after the last test suite: "%s"', $record['level'], $record['message']));
        }

        $this->records->discard();
    }
}
