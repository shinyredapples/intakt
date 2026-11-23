<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Core\Watcher\Contract\WatcherServices;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\ConfiguredRepositories;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// The watch section's `repository` is a local path, so the GitHub repository of a project has its own `github` key.
final readonly class WatchedRepositories implements ConfiguredRepositories
{
    /**
     * @param array<string, array{github?: string}> $projects
     */
    public function __construct(
        #[Autowire(param: WatcherServices::PROJECTS)]
        private array $projects,
    ) {
    }

    /**
     * @throws ForgeReadFailed
     */
    public function addressOf(string $project): RepositoryAddress
    {
        $name = $this->projects[$project]['github'] ?? throw new ForgeReadFailed(sprintf('Project "%s" has no GitHub repository in its watch section.', $project));
        [$owner, $repository] = [...explode('/', $name, 2), ''];

        return new RepositoryAddress($owner, $repository);
    }

    public function nameOf(string $project): ?string
    {
        return $this->projects[$project]['github'] ?? null;
    }
}
