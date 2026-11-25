<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use DateInterval;
use DateTimeImmutable;
use Intakt\Core\Review\Contract\ReviewCeiling;

// Reviewers only, because build lanes and slots have no source until the build lane lands.
final readonly class WhoIsWorking
{
    /** @var list<Lane> */
    private array $lanes;

    /** @var list<ReviewedRound> */
    private array $activeRounds;

    /**
     * @param list<Review> $reviews
     */
    // The time is used here and not kept, because the live board compares serialized boards and a kept time would make every push differ.
    public function __construct(DateTimeImmutable $now, array $reviews, private int $builds = 0)
    {
        $this->lanes = array_merge(...array_map(static fn (Review $review): array => self::lanesOf($review, $now), $reviews));
        $this->activeRounds = array_values(array_filter(array_map(static fn (Review $review): ?ReviewedRound => $review->activeRound(), $reviews)));
    }

    // Every round still open, also one whose first reviewer has not started yet, because Working now shows it as Reviewing and the headline must say the same (UX2 home, #3326).
    /**
     * @return list<ReviewedRound>
     */
    public function activeRounds(): array
    {
        return $this->activeRounds;
    }

    /**
     * @return list<Lane>
     */
    public function lanes(): array
    {
        return $this->lanes;
    }

    public function working(): int
    {
        return count($this->lanes);
    }

    // The quiet line of the shell: reviewers are the only lanes with a source today, so it counts them and names no total. A build has no lane yet but is counted, so the line never calls the board quiet while the headline says a build runs.
    public function statusLine(): string
    {
        $parts = array_filter([
            match ($this->working()) {
                0 => null,
                1 => '1 reviewer working',
                default => sprintf('%d reviewers working', $this->working()),
            },
            match ($this->builds) {
                0 => null,
                1 => '1 build running',
                default => sprintf('%d builds running', $this->builds),
            },
        ]);

        return [] === $parts ? 'Quiet · nobody working' : 'Running smoothly · '.implode(', ', $parts);
    }

    /**
     * @return list<Lane>
     */
    // A judged or ended round has nothing running, so a reviewer still marked running there is a stray row.
    private static function lanesOf(Review $review, DateTimeImmutable $now): array
    {
        $round = $review->activeRound();

        if (!$round instanceof ReviewedRound) {
            return [];
        }

        $running = array_filter($round->outcomes, static fn (ReviewerOutcome $outcome): bool => ReviewerProgress::Running === $outcome->progress && self::withinTheCeiling($outcome, $now));

        return array_values(array_map(static fn (ReviewerOutcome $outcome): Lane => new Lane($outcome->id, $outcome->role, $outcome->tier, $round->project, $round->change, $round->number, $outcome->since, $round->round), $running));
    }

    // A round nobody judges (an orchestrator crash, judging failed for good) would keep its reviewers running forever, so a lane ends where the worker's hold ends: past the review ceiling a redelivery may treat the worker as stopped (#1715).
    private static function withinTheCeiling(ReviewerOutcome $outcome, DateTimeImmutable $now): bool
    {
        return $outcome->since->add(new DateInterval('PT'.ReviewCeiling::SECONDS.'S')) > $now;
    }
}
