<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;

final readonly class ReviewedRound
{
    public string $round;
    public string $project;
    public int $reviewers;
    public string $change;
    public ?int $number;
    public ?RoundSource $source;

    /** @var list<ReviewerOutcome> */
    public array $outcomes;

    /** @var list<RoundFinding> */
    public array $findings;

    public bool $findingTextRecorded;
    public ReviewState $state;
    public TokenFigures $tokens;
    public Cost $cost;
    public ?Decision $outcome;

    public function __construct(
        RoundStart $start,
        RoundPlace $place,
        public DateTimeImmutable $startedAt,
        public ?QuorumPolicy $quorum,
    ) {
        $this->round = $start->round;
        $this->project = $start->project;
        $this->reviewers = $start->reviewers;
        $this->change = $place->change;
        $this->number = $place->number;
        $this->source = $place->source;
        $this->outcomes = [];
        $this->findings = [];
        $this->findingTextRecorded = true;
        $this->state = ReviewState::InProgress;
        $this->tokens = TokenFigures::none();
        $this->cost = Cost::none();
        $this->outcome = null;
    }

    public function failedReviewers(): int
    {
        return count(array_filter($this->outcomes, static fn (ReviewerOutcome $outcome): bool => ReviewerProgress::Failed === $outcome->progress));
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

    /**
     * @param list<RoundFinding> $marked
     */
    // Rounds judged before the Review module recorded the findings themselves keep only the ids the judge marked.
    public function judgedFromMarksOnly(array $marked): self
    {
        return clone ($this->judgedWith($marked), ['findingTextRecorded' => false]);
    }

    public function withTokens(TokenFigures $tokens): self
    {
        return clone ($this, ['tokens' => $tokens]);
    }

    public function withCost(Cost $cost): self
    {
        return clone ($this, ['cost' => $cost]);
    }

    public function decidedAs(Decision $outcome): self
    {
        return clone ($this, ['outcome' => $outcome]);
    }
}
