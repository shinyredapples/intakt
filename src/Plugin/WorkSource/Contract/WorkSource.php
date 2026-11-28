<?php

declare(strict_types=1);

namespace Intakt\Plugin\WorkSource\Contract;

use NoDiscard;

interface WorkSource
{
    // Reads nothing from the forge when the worker will not take work, because the routine re-reads of idle workers used up the forge budget.
    #[NoDiscard('a pick nobody looks at starts no build')]
    public function nextWork(string $project, WorkerState $worker): WorkPick;

    public function queue(string $project): WorkQueue;

    // Null when the listing came back short, because a report counted over part of it would hold the wrong number of tasks behind a hand-over.
    public function openWork(string $project): ?OpenWork;

    // Holds the tasks that wait on this one with the reason, because building on a missing base wastes the build.
    public function failedForGood(string $project, int $number, string $reason): void;
}
