<?php

declare(strict_types=1);

namespace Intakt\Plugin\WorkSource\Contract;

use DateTimeImmutable;

// Every open task of one listing, with the due dates of milestones that hold no open task, because a report over part of the listing would count the wrong tasks behind a hand-over.
final readonly class OpenWork
{
    /**
     * @param list<OpenTask>         $tasks
     * @param list<DateTimeImmutable> $emptyMilestones
     */
    public function __construct(
        public array $tasks,
        public array $emptyMilestones,
    ) {
    }
}
