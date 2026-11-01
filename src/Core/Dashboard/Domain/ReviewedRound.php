<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use DateTimeImmutable;

final readonly class ReviewedRound
{
    public string $round;
    public string $project;
    public int $reviewers;
    public string $change;
    public ?int $number;

    /** @var list<ReviewerOutcome> */
    public array $outcomes;

    /** @var list<RoundFinding> */
    public array $findings;

    public ReviewState $state;
    public TokenCount $tokens;

    public function __construct(
        RoundStart $start,
        RoundPlace $place,
        public DateTimeImmutable $startedAt,
    ) {
        $this->round = $start->round;
        $this->project = $start->project;
        $this->reviewers = $start->reviewers;
        $this->change = $place->change;
        $this->number = $place->number;
        $this->outcomes = [];
        $this->findings = [];
        $this->state = ReviewState::InProgress;
        $this->tokens = TokenCount::none();
    }

    // A round without a change name has no siblings it could be told apart from, so it stands alone.
    public function isOfTheSameReviewAs(self $other): bool
    {
        if ('' === $this->change || '' === $other->change) {
            return $this->round === $other->round;
        }

        return $this->project === $other->project && $this->change === $other->change;
    }

    /**
     * @param list<ReviewerOutcome> $outcomes
     */
    public function withOutcomes(array $outcomes): self
    {
        return clone ($this, ['outcomes' => $outcomes]);
    }

    /**
     * @param list<RoundFinding> $findings
     */
    public function judgedWith(array $findings): self
    {
        return clone ($this, ['findings' => $findings, 'state' => ReviewState::Judged]);
    }

    public function withTokens(TokenCount $tokens): self
    {
        return clone ($this, ['tokens' => $tokens]);
    }
}
