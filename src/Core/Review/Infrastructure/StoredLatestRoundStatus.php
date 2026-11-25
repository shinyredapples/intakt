<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\LatestRoundStatus;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Plugin\Forge\Contract\RoundStatusLabel;
use Override;

final readonly class StoredLatestRoundStatus implements LatestRoundStatus
{
    public function __construct(
        private ReviewRounds $rounds,
    ) {
    }

    // A judged round stands for a published verdict, because the verdict is published right after its judging and no publication is stored.
    #[Override]
    public function of(string $project, string $change): ?RoundStatusLabel
    {
        return match ($this->rounds->latestStateOf($project, $change)) {
            null => null,
            RoundState::Judged => RoundStatusLabel::WaitingOnYou,
            RoundState::Collecting, RoundState::Complete => RoundStatusLabel::InReview,
        };
    }
}
