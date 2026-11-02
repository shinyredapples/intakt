<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use RuntimeException;

final class ProjectNotWatched extends RuntimeException
{
    public static function named(string $name): self
    {
        return new self(sprintf('Project "%s" has no watch section with a repository, base and task.', $name));
    }
}
