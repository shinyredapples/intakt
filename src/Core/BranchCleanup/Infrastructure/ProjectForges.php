<?php

declare(strict_types=1);

namespace Intakt\Core\BranchCleanup\Infrastructure;

use Intakt\Core\Watcher\Contract\WatcherServices;
use Intakt\Plugin\Forge\Contract\ForgeBranches;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class ProjectForges
{
    /**
     * @param iterable<ForgeBranches>                  $forges
     * @param array<string, array{repository: string}> $projects
     */
    public function __construct(
        #[AutowireIterator(ForgeBranches::class)]
        private iterable $forges,
        #[Autowire(param: WatcherServices::PROJECTS)]
        private array $projects,
    ) {
    }

    /**
     * @return list<string>
     */
    public function projects(): array
    {
        return array_map(strval(...), array_keys($this->projects));
    }

    /**
     * @throws ForgeReadFailed
     */
    public function forgeOf(string $project): ForgeBranches
    {
        foreach ($this->forges as $forge) {
            if ($forge->servesProject($project)) {
                return $forge;
            }
        }

        throw new ForgeReadFailed(sprintf('No forge serves the branches of project "%s".', $project));
    }

    /**
     * @throws ForgeReadFailed
     */
    public function repositoryOf(string $project): string
    {
        return $this->projects[$project]['repository'] ?? throw new ForgeReadFailed(sprintf('Project "%s" has no watch section.', $project));
    }
}
