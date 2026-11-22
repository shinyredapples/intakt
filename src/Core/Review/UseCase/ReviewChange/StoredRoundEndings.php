<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReviewChange;

use Intakt\Core\Review\Contract\RoundEndingLookup;
use Intakt\Core\Review\Contract\RoundEndings;
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
    public function lookup(): RoundEndingLookup
    {
        return new StoredRoundEndingLookup(
            $this->failedJudgings->recordedTimes(),
            $this->failedJudgings->parked(),
            $this->clock,
            $this->upperWait,
            $this->reasons,
        );
    }
}
