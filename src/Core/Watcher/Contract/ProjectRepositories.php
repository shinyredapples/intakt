<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Contract;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ProjectRepositories
{
    /**
     * @param array<string, array{repository: string}> $projects
     */
    public function __construct(
        #[Autowire(param: WatcherServices::PROJECTS)]
        private array $projects,
    ) {
    }

    /**
     * @throws ProjectHasNoRepository
     */
    public function of(string $project): string
    {
        return $this->projects[$project]['repository'] ?? throw ProjectHasNoRepository::named($project);
    }
}
