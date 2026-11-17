<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\TourCaption;
use Intakt\Core\Dashboard\UseCase\CreateDemoProject\CreateDemoProjectCommand;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Attribute\AsTwigFunction;

final readonly class DemoTour
{
    public function __construct(
        private LedgerReviewPages $reviews,
        #[Autowire(env: 'INTAKT_COMPOSE_SERVICE')]
        private string $composeService,
    ) {
    }

    // Null outside the compose demo without reading the ledger, because the demo project exists only there and every page asks.
    #[AsTwigFunction('demo_tour')]
    public function caption(): ?TourCaption
    {
        if ('' === $this->composeService) {
            return null;
        }

        $review = $this->reviews->latestReviewOf(CreateDemoProjectCommand::NAME);

        // A review with a live round is no demo run, because only a replay writes rounds of a flagged project.
        if (!$review instanceof Review || !$review->everyRoundIsAReplay()) {
            return TourCaption::beforeTheRun();
        }

        return TourCaption::of($review);
    }
}
