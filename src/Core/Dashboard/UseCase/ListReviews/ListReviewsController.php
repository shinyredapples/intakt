<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ListReviews;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Domain\ReviewRow;
use Intakt\Core\Dashboard\Domain\ReviewSummary;
use Intakt\Core\Dashboard\Infrastructure\FilterProjects;
use Intakt\Core\Dashboard\Infrastructure\LedgerFailingHeads;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviews;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Plugin\Forge\Contract\ForgeBudgets;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ListReviewsController
{
    public function __construct(
        private LedgerReviews $reviews,
        private LedgerFailingHeads $failingHeads,
        private LivePageRendering $pages,
        private ForgeBudgets $forgeBudgets,
        private FilterProjects $filterProjects,
        private ClockInterface $clock,
    ) {
    }

    #[Route('/reviews', name: DashboardRoutes::LIST_REVIEWS, methods: ['GET'])]
    // The project only chooses the top bar filter: every row stays on the page, so the filter can switch to any project it offers.
    public function __invoke(Request $request, #[MapQueryParameter] ?string $project = null): Response
    {
        return new Response($this->pages->render($request, 'dashboard/reviews.html.twig', [
            'reviews' => $this->rows(),
            'failing' => $this->failingHeads->now(),
            'forge_budgets' => $this->forgeBudgets->thisHour(),
            'linked_project' => $project,
            'project_trail' => $this->listed($project),
        ]));
    }

    /**
     * @return list<ReviewRow>
     */
    private function rows(): array
    {
        $now = $this->clock->now();

        return array_map(static fn (ReviewSummary $review): ReviewRow => new ReviewRow($review, $now), $this->reviews->newestFirst());
    }

    // A name the install does not list is no option of the filter, which then shows every project, so no breadcrumb or rail mark may name it.
    private function listed(?string $project): ?string
    {
        return in_array($project, $this->filterProjects->names(), true) ? $project : null;
    }
}
