<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowFinding;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Dashboard\Infrastructure\FindingPaths;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

// A finding's own page, opened from its row among the things to fix, so the overview stays short and the reasoning is one tap away.
final readonly class ShowFindingController
{
    public function __construct(
        private LedgerReviewPages $reviews,
        private LivePageRendering $pages,
        private FindingPaths $paths,
    ) {
    }

    // Any finding id, encoded by FindingPaths, because a reviewer names its own ids and one may hold a slash.
    #[Route('/reviews/{round}/findings/{finding}', name: DashboardRoutes::SHOW_FINDING, requirements: ['finding' => '.+'], methods: ['GET'])]
    public function __invoke(Request $request, string $round, string $finding): Response
    {
        $review = $this->reviews->reviewOf($round);
        $reviewed = $review instanceof Review ? $review->judgedRound($round) : null;
        $found = $reviewed instanceof ReviewedRound ? $reviewed->finding($this->paths->findingIn($finding)) : null;

        if (!$review instanceof Review || !$reviewed instanceof ReviewedRound || !$found instanceof RoundFinding) {
            throw new NotFoundHttpException('The ledger holds no judged finding with this id in this round.');
        }

        return new Response($this->pages->render($request, 'dashboard/finding.html.twig', [
            'review' => $review,
            'reviewed' => $reviewed,
            'finding' => $found,
        ]));
    }
}
