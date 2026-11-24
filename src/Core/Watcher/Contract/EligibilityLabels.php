<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Contract;

// The one reading of each project's eligibility label, so a work source asks for the label instead of depending on the shape of the watch sections.
final readonly class EligibilityLabels
{
    /** @var array<string, string> */
    private array $labels;

    public function __construct(ProjectEligibilityLabel ...$labels)
    {
        $byProject = [];

        foreach ($labels as $label) {
            $byProject[$label->project] = $label->label;
        }

        $this->labels = $byProject;
    }

    public function of(string $project): string
    {
        return $this->labels[$project] ?? throw ProjectNamesNoEligibilityLabel::named($project);
    }
}
