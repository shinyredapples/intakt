<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// Reviewers only, because build lanes and slots have no source until the build lane lands.
final readonly class WhoIsWorking
{
    /** @var list<Lane> */
    private array $lanes;

    /**
     * @param list<Review> $reviews
     */
    public function __construct(array $reviews)
    {
        $this->lanes = array_merge(...array_map($this->lanesOf(...), $reviews));
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

    /**
     * @return list<Lane>
     */
    // A judged round has nothing running, so a reviewer still marked running there is a stray row.
    private function lanesOf(Review $review): array
    {
        $round = $review->activeRound();

        if (!$round instanceof ReviewedRound) {
            return [];
        }

        $running = array_filter($round->outcomes, static fn (ReviewerOutcome $outcome): bool => ReviewerProgress::Running === $outcome->progress);

        return array_values(array_map(static fn (ReviewerOutcome $outcome): Lane => new Lane($outcome->id, $outcome->role, $outcome->tier, $round->project, $round->change, $round->number, $outcome->since), $running));
    }
}
