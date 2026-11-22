<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

final readonly class OwnerNoticesOfTheDay
{
    /**
     * @param list<OutageNotice>      $waitingOldestFirst
     * @param list<EndedOutageNotice> $endedNewestFirst
     */
    public function __construct(
        public array $waitingOldestFirst,
        public array $endedNewestFirst,
    ) {
    }
}
