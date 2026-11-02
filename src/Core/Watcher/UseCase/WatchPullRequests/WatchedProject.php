<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

final readonly class WatchedProject
{
    public function __construct(
        public string $name,
        public string $repository,
        public string $base,
        // One text for every pull request until the pull request text is read from the forge.
        public string $task,
    ) {
    }
}
