<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReviewChange;

use DateTimeImmutable;
use Intakt\Core\Review\Contract\RoundEnding;
use Intakt\Core\Review\Contract\RoundEndingLookup;
use Intakt\Core\Review\Contract\RoundEnds;
use Intakt\Core\Review\UseCase\JudgeRound\FailedJudgings;
use Symfony\Component\Clock\ClockInterface;

// Not readonly, because it keeps the transport's listing once a round needed it; it lives for one ledger read only.
final class StoredRoundEndingLookup implements RoundEndingLookup
{
    /** @var array<string, true>|null */
    private ?array $parked = null;

    /**
     * @param array<string, DateTimeImmutable> $failedAt
     */
    public function __construct(
        private readonly array $failedAt,
        private readonly FailedJudgings $failedJudgings,
        private readonly ClockInterface $clock,
        private readonly UpperWait $upperWait,
        private readonly RoundEndReasons $reasons,
    ) {
    }

    public function ofUnjudged(string $roundId, int $reviewers, DateTimeImmutable $startedAt): ?RoundEnding
    {
        $failedAt = $this->failedAt[$roundId] ?? null;

        if ($failedAt instanceof DateTimeImmutable || $this->isParked($roundId)) {
            return new RoundEnding(RoundEnds::JudgingFailed, $this->reasons->judgingFailed(), $failedAt);
        }

        $seconds = $this->upperWait->secondsForReviewers($reviewers);
        $deadline = $startedAt->modify('+'.$seconds.' seconds');

        if ($this->clock->now() < $deadline) {
            return null;
        }

        return new RoundEnding(RoundEnds::NoOutcomeInTime, $this->reasons->noOutcomeInTime($seconds), $deadline);
    }

    // Listed on the first round the ledger records no failure of, so a read whose failures the ledger holds never lists the transport, and a read of many rounds lists it once.
    private function isParked(string $roundId): bool
    {
        $this->parked ??= $this->failedJudgings->parked();

        return isset($this->parked[$roundId]);
    }
}
