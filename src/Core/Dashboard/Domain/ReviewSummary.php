<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use DateTimeImmutable;

final readonly class ReviewSummary
{
    public string $round;
    public string $project;
    public int $reviewers;
    public string $change;
    public ?int $number;
    public ReviewState $state;
    public DateTimeImmutable $startedAt;
    public ?ReviewOutcome $outcome;
    public ?int $findingCount;

    // The count is null until the judge is done, because a round in progress has no judged findings yet and 0 would read as a clean round.
    public function __construct(ReviewedRound $round)
    {
        $this->round = $round->round;
        $this->project = $round->project;
        $this->reviewers = $round->reviewers;
        $this->change = $round->change;
        $this->number = $round->number;
        $this->state = $round->state;
        $this->startedAt = $round->startedAt;
        $this->outcome = ReviewOutcome::of($round);
        $this->findingCount = $round->isJudged() ? count($round->findings) : null;
    }
}
