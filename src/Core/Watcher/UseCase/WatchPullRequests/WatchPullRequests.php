<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

// One tick of one project, sent by the Scheduler.
final readonly class WatchPullRequests
{
    public function __construct(public string $project)
    {
    }
}
