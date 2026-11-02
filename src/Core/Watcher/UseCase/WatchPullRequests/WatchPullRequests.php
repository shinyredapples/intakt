<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

// Carries the project only, because the Scheduler builds one message per project.
final readonly class WatchPullRequests
{
    public function __construct(public string $project)
    {
    }
}
