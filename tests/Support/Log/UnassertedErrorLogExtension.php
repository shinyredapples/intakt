<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log;

use PHPUnit\Runner\Extension\ChildProcessExtension;
use PHPUnit\Runner\Extension\ChildProcessFacade;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

final class UnassertedErrorLogExtension implements ChildProcessExtension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $records = LogRecords::startRun();
        $facade->registerSubscribers(new WarnOnUnassertedErrors($records), new WarnOnErrorsAfterASuite($records), new WarnOnErrorsAfterTheRun($records));
    }

    // An isolated test logs in its own process, so that process checks its own records.
    public function bootstrapChildProcess(Configuration $configuration, ChildProcessFacade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new WarnOnUnassertedErrors(LogRecords::startRun()));
    }

    public function shutdownChildProcess(): void
    {
        LogRecords::ofThisRun()?->discard();
    }
}
