<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowFinding;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

// A finding's own page, opened from its card in the review's To fix list, so the overview stays short and the reasoning is one tap away.
final readonly class ShowFindingController
{
    public function __construct(
        private LedgerReviewPages $reviews,
        private Environment $twig,
    ) {
    }

    // Any finding id, because a reviewer names its own ids and one may hold a slash.
    #[Route('/reviews/{round}/findings/{finding}', name: DashboardRoutes::SHOW_FINDING, requirements: ['finding' => '.+'], methods: ['GET'])]
    public function __invoke(string $round, string $finding): Response
    {
        $review = $this->reviews->reviewOf($round);
        $reviewed = $review instanceof Review ? $review->judgedRound($round) : null;
        $found = $reviewed instanceof ReviewedRound ? $reviewed->finding($finding) : null;

        if (!$review instanceof Review || !$reviewed instanceof ReviewedRound || !$found instanceof RoundFinding) {
            throw new NotFoundHttpException('The ledger holds no judged finding with this id in this round.');
        }

        return new Response($this->twig->render('dashboard/finding.html.twig', [
            'review' => $review,
            'reviewed' => $reviewed,
            'finding' => $found,
        ]));
    }
}
