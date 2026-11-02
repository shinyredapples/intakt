<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

final readonly class WatchedProject
{
    public function __construct(
        public string $name,
        public string $repository,
        public string $base,
        // Shared by every pull request because the forge's pull request text is not read yet.
        public string $task,
    ) {
    }
}
