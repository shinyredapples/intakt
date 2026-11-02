<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Review\Contract\ChangeSource;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class WatchedProjects
{
    /**
     * @param array<string, array{repository: string, base: string, task: string}> $projects
     */
    public function __construct(
        #[Autowire('%intakt.watcher.projects%')]
        private array $projects,
    ) {
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(strval(...), array_keys($this->projects));
    }

    public function named(string $name): WatchedProject
    {
        $project = $this->projects[$name] ?? throw ProjectNotWatched::named($name);

        return new WatchedProject($name, new ChangeSource($project['repository'], $project['base']), $project['task']);
    }
}
