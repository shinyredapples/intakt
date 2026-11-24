<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\ProjectSummary;
use Intakt\Core\Dashboard\Domain\ReviewSummary;
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
        return $this->namesWith([]);
    }

    // A page whose rows name projects with neither a review nor build work (History, with the watcher's events) adds them, so each row's project can be chosen.
    /**
     * @param list<string> $shown
     *
     * @return list<string>
     */
    public function namesWith(array $shown): array
    {
        // A project with build work and no review yet is listed too, because the board shows its asleep octopus only while the filter shows that project (#2732).
        $names = array_values(array_unique([...array_map(
            static fn (ProjectSummary $project): string => $project->name,
            ProjectSummary::fromReviews($this->reviews->newestFirst()),
        ), ...$this->reviews->builtProjects(), ...$shown]));
        // Name order, because the order of first reviews means nothing to a reader looking for one project in a long list; names the natural order calls equal (case, spacing) fall back to byte order, so the order never depends on the ledger's and the live filter update can rebuild it (base.html.twig).
        usort($names, static fn (string $one, string $other): int => strnatcasecmp($one, $other) ?: strcmp($one, $other));

        return $names;
    }

    public function isFirstRoundOf(string $round, string $project): bool
    {
        $rounds = array_filter($this->reviews->newestFirst(), static fn (ReviewSummary $review): bool => $review->project === $project);

        return $round === array_last($rounds)?->round;
    }
}
