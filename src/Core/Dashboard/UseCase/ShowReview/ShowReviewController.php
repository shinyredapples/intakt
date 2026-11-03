<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowReview;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;
use Twig\Environment;

final readonly class ShowReviewController
{
    public function __construct(
        private LedgerReviewPages $reviews,
        private Environment $twig,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/reviews/{round}', name: DashboardRoutes::SHOW_REVIEW, methods: ['GET'])]
    public function __invoke(Request $request, string $round): Response
    {
        $review = $this->reviews->reviewOf($round);

        if (!$review instanceof Review) {
            throw new NotFoundHttpException('The ledger holds no review with this round.');
        }

        return new Response($this->rendered($review, $round, $request));
    }

    private function rendered(Review $review, string $round, Request $request): string
    {
        $flashes = $request->getSession()->getFlashBag()->peekAll();

        try {
            return $this->twig->render('dashboard/review.html.twig', ['review' => $review, 'round' => $round, 'live' => true]);
        } catch (Throwable $failure) {
            // Rendered again without the subscription, because a hub that cannot authorize a listener must leave the plain page standing; the class only, because a message can echo a token.
            // Put back, because the failed attempt already read the one-time messages.
            $request->getSession()->getFlashBag()->setAll($flashes);
            $this->logger->warning('The review page was rendered without live updates.', ['exception' => $failure::class]);

            return $this->twig->render('dashboard/review.html.twig', ['review' => $review, 'round' => $round, 'live' => false]);
        }
    }
}
