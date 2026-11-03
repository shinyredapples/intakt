<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log;

use PHPUnit\Event\TestRunner\Finished;
use PHPUnit\Event\TestRunner\FinishedSubscriber;

final readonly class DiscardLogRecords implements FinishedSubscriber
{
    public function __construct(private LogRecords $records)
    {
    }

    public function notify(Finished $event): void
    {
        $this->records->discard();
    }
}
