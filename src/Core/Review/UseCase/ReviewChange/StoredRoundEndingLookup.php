<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReviewChange;

use DateTimeImmutable;
use Intakt\Core\Review\Contract\RoundEnding;
use Intakt\Core\Review\Contract\RoundEndingLookup;
use Intakt\Core\Review\Contract\RoundEnds;
use Symfony\Component\Clock\ClockInterface;

final readonly class StoredRoundEndingLookup implements RoundEndingLookup
{
    /**
     * @param array<string, DateTimeImmutable> $failedAt
     * @param array<string, true>              $parked
     */
    public function __construct(
        private array $failedAt,
        private array $parked,
        private ClockInterface $clock,
        private UpperWait $upperWait,
        private RoundEndReasons $reasons,
    ) {
    }

    public function ofUnjudged(string $roundId, int $reviewers, DateTimeImmutable $startedAt): ?RoundEnding
    {
        $failedAt = $this->failedAt[$roundId] ?? null;

        if ($failedAt instanceof DateTimeImmutable || isset($this->parked[$roundId])) {
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
