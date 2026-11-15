<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\MarkReplay;

use Intakt\Core\Platform\Contract\AsQueryHandler;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\ReplayMarkOfRound;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;

#[AsQueryHandler]
final readonly class ReplayMarkOfRoundHandler
{
    public function __construct(private ReviewRounds $rounds)
    {
    }

    public function __invoke(ReplayMarkOfRound $query): ReplayMark
    {
        return $this->rounds->stored($query->roundId)->replayMark();
    }
}
