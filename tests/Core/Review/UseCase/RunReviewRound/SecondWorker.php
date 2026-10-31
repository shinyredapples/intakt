<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Symfony\Component\Uid\Uuid;

final readonly class SecondWorker
{
    public function __construct(
        public ReviewWithReviewerHandler $handler,
        private EntityManagerInterface $entities,
        public InMemoryLedger $ledger,
    ) {
    }

    // Read through this worker's own connection, so the first worker's entity manager is left as its delivery left it.
    public function roundState(Uuid $roundId): RoundState
    {
        $this->entities->clear();
        $round = $this->entities->find(ReviewRound::class, $roundId);
        assert($round instanceof ReviewRound);

        return $round->state();
    }

    public function reviewerState(string $reviewerId): ReviewerState
    {
        $this->entities->clear();
        $reviewer = $this->entities->find(RoundReviewer::class, Uuid::fromString($reviewerId));
        assert($reviewer instanceof RoundReviewer);

        return $reviewer->state();
    }
}
