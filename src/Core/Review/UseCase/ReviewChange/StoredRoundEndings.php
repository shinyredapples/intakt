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

    // The failure transport still counts, because a judging parked there before the DispatchOnFailureStamp (#1848) has no ledger record and has failed all the same.
    public function ofUnjudged(string $roundId, int $reviewers, DateTimeImmutable $startedAt): ?RoundEnding
    {
        $failedAt = $this->failedJudgings->recordedAt($roundId);

        if ($failedAt instanceof DateTimeImmutable || $this->failedJudgings->isParked($roundId)) {
            return new RoundEnding(RoundEnds::JudgingFailed, $this->reasons->judgingFailed(), $failedAt);
        }

        $seconds = $this->upperWait->secondsForReviewers($reviewers);
        $deadline = $startedAt->modify('+'.$seconds.' seconds');

        if ($this->clock->now() < $deadline) {
            return null;
        }

        return new RoundEnding(RoundEnds::NoOutcomeInTime, $this->reasons->noOutcomeInTime($seconds), $deadline);
    }
}
