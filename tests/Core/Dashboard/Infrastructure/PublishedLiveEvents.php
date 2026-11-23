<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Override;

final class PublishedLiveEvents implements LiveUpdates
{
    /** @var list<LiveEvent> */
    public private(set) array $events = [];

    #[Override]
    public function publish(LiveEvent $event): bool
    {
        $this->events[] = $event;

        return true;
    }
}
