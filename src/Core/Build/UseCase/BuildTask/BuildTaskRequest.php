<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

final readonly class BuildTaskRequest
{
    public function __construct(
        public string $project,
        public int $issue,
        public string $remote,
        public string $branch,
        public string $directory,
    ) {
    }
}
