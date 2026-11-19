<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReviewChange;

use DateTimeImmutable;
use Intakt\Core\Review\Contract\RoundEnding;
use Intakt\Core\Review\Contract\RoundEndings;
use Intakt\Core\Review\Contract\RoundEnds;
use Intakt\Core\Review\UseCase\JudgeRound\FailedJudgings;
use Symfony\Component\Clock\ClockInterface;

final readonly class StoredRoundEndings implements RoundEndings
{
    public function __construct(
        private FailedJudgings $failedJudgings,
        private ClockInterface $clock,
        private UpperWait $upperWait = new UpperWait(),
        private RoundEndReasons $reasons = new RoundEndReasons(),
    ) {
    }

    // The ledger record alone, because the judging's DispatchOnFailureStamp keeps a judging that failed for good out of the failure transport (#1848).
    public function ofUnjudged(string $roundId, int $reviewers, DateTimeImmutable $startedAt): ?RoundEnding
    {
        if ($this->failedJudgings->isRecorded($roundId)) {
            return new RoundEnding(RoundEnds::JudgingFailed, $this->reasons->judgingFailed());
        }

        $seconds = $this->upperWait->secondsForReviewers($reviewers);

        if ($this->clock->now() < $startedAt->modify('+'.$seconds.' seconds')) {
            return null;
        }

        return new RoundEnding(RoundEnds::NoOutcomeInTime, $this->reasons->noOutcomeInTime($seconds));
    }
}
