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
    public function __construct(array $reviews, private int $builds = 0)
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
    private function lanesOf(Review $review): array
    {
        $round = $review->activeRound();

        if (!$round instanceof ReviewedRound) {
            return [];
        }

        $running = array_filter($round->outcomes, static fn (ReviewerOutcome $outcome): bool => ReviewerProgress::Running === $outcome->progress);

        return array_values(array_map(static fn (ReviewerOutcome $outcome): Lane => new Lane($outcome->id, $outcome->role, $outcome->tier, $round->project, $round->change, $round->number, $outcome->since, $round->round), $running));
    }
}
