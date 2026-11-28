<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Override;

// A hub that does not take its first sends, because a caller has to tell a refused send from a delivered one.
final class RefusingLiveEvents implements LiveUpdates
{
    /** @var list<LiveEvent> */
    public private(set) array $events = [];

    public private(set) int $refused = 0;

    public function __construct(
        private readonly int $refusals,
    ) {
    }

    #[Override]
    public function publish(LiveEvent $event): bool
    {
        if ($this->refused < $this->refusals) {
            ++$this->refused;

            return false;
        }

        $this->events[] = $event;

        return true;
    }
}
