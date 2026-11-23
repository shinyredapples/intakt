<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Contract;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

// The one reading of each project's eligibility label, so a work source asks for the label instead of depending on the shape of the watch sections.
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
        return $this->projects[$project]['eligibility_label'] ?? throw ProjectNamesNoEligibilityLabel::named($project);
    }
}
