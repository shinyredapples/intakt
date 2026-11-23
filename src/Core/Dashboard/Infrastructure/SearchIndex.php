<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Domain\ProjectSummary;
use Intakt\Core\Dashboard\Domain\ReviewSummary;
use Intakt\Core\Dashboard\Domain\SearchAction;
use Intakt\Core\Dashboard\Domain\SearchAnswer;
use Intakt\Core\Dashboard\Domain\SearchGroup;
use Intakt\Core\Dashboard\Domain\SearchQuery;
use Intakt\Core\Dashboard\Domain\SearchResult;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class SearchIndex
{
    // The dashboard keeps no visit history, so the newest rounds stand in for what the owner opened last.
    private const int RECENT_ROUNDS = 5;

    private const array ACTIONS = [SearchAction::SwitchTheme];

    private const array PAGES = [
        DashboardRoutes::LIST_REVIEWS => ['Reviews', 'All projects, every review round'],
        DashboardRoutes::LIST_PROJECTS => ['Projects', 'All projects, every project that has started a review'],
        DashboardRoutes::SHOW_TRENDS => ['Trends', 'All projects, how the reviews went'],
        DashboardRoutes::SHOW_TEAM => ['Team', 'All projects, accounts and the roles they serve'],
        DashboardRoutes::SHOW_COMPONENTS => ['Components', "All projects, the dashboard's building blocks"],
    ];

    public function __construct(
        private LedgerReviews $reviews,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function answer(SearchQuery $query): SearchAnswer
    {
        $reviews = $this->reviews->newestFirst();

        if ($query->isEmpty()) {
            return SearchAnswer::of($query, [...$this->recent($reviews), ...$this->actions()]);
        }

        $found = array_values(array_filter($this->entries($reviews), $query->matches(...)));

        return [] === $found ? SearchAnswer::nothingFor($query, $this->suggestion($reviews)) : SearchAnswer::of($query, $found);
    }

    /**
     * @param list<ReviewSummary> $reviews
     *
     * @return list<SearchResult>
     */
    private function recent(array $reviews): array
    {
        return array_map(
            fn (ReviewSummary $review): SearchResult => $this->reviewResult($review)->inGroup(SearchGroup::Recent),
            array_slice($reviews, 0, self::RECENT_ROUNDS),
        );
    }

    /**
     * @return list<SearchResult>
     */
    private function actions(): array
    {
        return array_map(static fn (SearchAction $action): SearchResult => $action->result(), self::ACTIONS);
    }

    /**
     * @param list<ReviewSummary> $reviews
     *
     * @return list<SearchResult>
     */
    private function entries(array $reviews): array
    {
        return [
            ...$this->actions(),
            ...array_map($this->reviewResult(...), $reviews),
            ...array_map($this->projectResult(...), ProjectSummary::fromReviews($reviews)),
            ...$this->pages(),
        ];
    }

    private function reviewResult(ReviewSummary $review): SearchResult
    {
        return new SearchResult(
            SearchGroup::Reviews,
            $review->title(),
            $review->project.', '.$review->state->value,
            $this->urls->generate(DashboardRoutes::SHOW_REVIEW, ['round' => $review->round]),
            roundId: $review->round,
            outcome: $review->outcome,
        );
    }

    private function projectResult(ProjectSummary $project): SearchResult
    {
        return new SearchResult(
            SearchGroup::Projects,
            $project->name,
            'Project, '.$project->rounds.(1 === $project->rounds ? ' review round' : ' review rounds'),
            $this->urls->generate(DashboardRoutes::LIST_REVIEWS, ['project' => $project->name]),
        );
    }

    /**
     * @return list<SearchResult>
     */
    private function pages(): array
    {
        $pages = [];

        foreach (self::PAGES as $route => [$label, $detail]) {
            $pages[] = new SearchResult(SearchGroup::Pages, $label, $detail, $this->urls->generate($route));
        }

        return $pages;
    }

    /**
     * @param list<ReviewSummary> $reviews
     */
    private function suggestion(array $reviews): string
    {
        $project = array_first(ProjectSummary::fromReviews($reviews));

        return null === $project
            ? 'Try a page such as Reviews or Projects.'
            : 'Try a project such as '.$project->name.', a round id, or a page such as Reviews.';
    }
}
