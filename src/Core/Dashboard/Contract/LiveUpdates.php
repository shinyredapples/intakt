<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Contract;

// An implementation never throws: a live update is best effort, and a page or a caller must work the same while the hub is down.
interface LiveUpdates
{
    public function publish(LiveEvent $event): void;
}
