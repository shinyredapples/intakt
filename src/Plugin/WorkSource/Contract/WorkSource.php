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

    // Holds the tasks that wait on this one, with the reason, until it is built on a base that exists.
    public function failedForGood(string $project, int $number, string $reason): void;
}
