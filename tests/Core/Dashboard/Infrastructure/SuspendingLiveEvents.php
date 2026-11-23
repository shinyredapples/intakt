<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Fiber;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Override;

// Stops its fiber inside one publish, so a test can let another worker publish while the hub still sends the first update.
final class SuspendingLiveEvents implements LiveUpdates
{
    /** @var list<string> */
    public private(set) array $received = [];

    public function __construct(private readonly string $suspendingOn)
    {
    }

    #[Override]
    public function publish(LiveEvent $event): bool
    {
        $this->received[] = $event->turboStream;

        if ($this->suspendingOn === $event->turboStream) {
            Fiber::suspend();
        }

        return true;
    }
}
