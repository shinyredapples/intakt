<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Infrastructure;

use Intakt\Core\Watcher\Contract\WatcherServices;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// The label is the project's own to name; without one the default family name of docs/design/05-architecture/04-labels.md applies.
final readonly class EligibilityLabels
{
    private const string DEFAULT = 'intakt';

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
        return $this->projects[$project]['eligibility_label'] ?? self::DEFAULT;
    }
}
