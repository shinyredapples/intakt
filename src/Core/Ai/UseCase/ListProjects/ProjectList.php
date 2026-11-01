<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\UseCase\ListProjects;

final readonly class ProjectList
{
    /**
     * @param list<string> $projects
     */
    public function __construct(
        public array $projects,
    ) {
    }
}
