<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\ProjectSummary;
use Twig\Attribute\AsTwigFunction;

final readonly class FilterProjects
{
    public function __construct(
        private LedgerReviews $reviews,
    ) {
    }

    /**
     * @return list<string>
     */
    #[AsTwigFunction('filter_projects')]
    public function names(): array
    {
        $names = array_map(
            static fn (ProjectSummary $project): string => $project->name,
            ProjectSummary::fromReviews($this->reviews->newestFirst()),
        );
        // Name order, because the order of first reviews means nothing to a reader looking for one project in a long list.
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }
}
