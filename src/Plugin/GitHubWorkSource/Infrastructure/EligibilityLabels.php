<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Infrastructure;

use Intakt\Core\Watcher\Contract\WatcherServices;
use Intakt\Plugin\GitHubWorkSource\Contract\WorkLabels;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// Because the label is the project's own to name, and a project that names none gets the default of the design.
final readonly class EligibilityLabels
{
    /**
     * @param array<string, array{eligibility_label?: string}> $projects
     */
    public function __construct(
        #[Autowire(param: WatcherServices::PROJECTS)]
        private array $projects,
    ) {
    }

    public function of(string $project): string
    {
        return $this->projects[$project]['eligibility_label'] ?? WorkLabels::ELIGIBILITY;
    }
}
