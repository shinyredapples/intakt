<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Review\Contract\RoundJudged;
use Intakt\Core\Review\UseCase\RunReviewRound\LiveRoundProgress;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

// After the judge's record is committed, so the progress read under the publication lock already shows the round judged.
#[AsEventListener]
final readonly class PublishJudgedRound
{
    public function __construct(
        private LiveRoundProgress $progress,
    ) {
    }

    public function __invoke(RoundJudged $judged): void
    {
        $this->progress->publishLatest(Uuid::fromString($judged->roundId));
    }
}
