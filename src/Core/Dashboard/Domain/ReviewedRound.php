<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\RoundEnding;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use LogicException;

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

    /** @var list<RoundFinding> */
    public array $dropped;

    public bool $findingTextRecorded;
    public ReviewState $state;
    public TokenFigures $tokens;
    public Cost $cost;
    public ?Decision $outcome;
    public ?DateTimeImmutable $judgedAt;

    // The mark is null until the round is read with its review, because the start row does not say whether the round is a replay.
    public function __construct(
        RoundStart $start,
        RoundPlace $place,
        public DateTimeImmutable $startedAt,
        public ?QuorumPolicy $quorum,
        public ?RoundEnding $ending = null,
        public ?ReplayMark $mark = null,
    ) {
        $this->round = $start->round;
        $this->project = $start->project;
        $this->reviewers = $start->reviewers;
        $this->change = $place->change;
        $this->number = $place->number;
        $this->source = $place->source;
        $this->outcomes = [];
        $this->findings = [];
        $this->dropped = [];
        $this->findingTextRecorded = true;
        $this->state = ReviewState::InProgress;
        $this->tokens = TokenFigures::none();
        $this->cost = Cost::none();
        $this->outcome = null;
        $this->judgedAt = null;
    }

    // A replay shows like a live round, so the pages mark it, because its verdicts were never given on the change.
    public function isReplay(): bool
    {
        return ReplayMark::Replay === $this->mark;
    }

    // Refused for a round never marked, so the status cannot report an unread round as live.
    public function replayMark(): ReplayMark
    {
        return $this->mark ?? throw new LogicException(sprintf('Round %s was read without its replay mark.', $this->round));
    }

    public function isJudged(): bool
    {
        return $this->state->isJudged();
    }

    // Ended without the judge's call, as blocked, so the page and the tool name the same end.
    public function hasEnded(): bool
    {
        return $this->ending instanceof RoundEnding;
    }

    /**
     * @return list<RoundFinding>
     */
    public function actFindings(): array
    {
        return [...array_filter($this->findings, static fn (RoundFinding $finding): bool => JudgeCall::Act === $finding->call)];
    }

    public function reviewOutcome(): ?ReviewOutcome
    {
        return ReviewOutcome::of($this);
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
    public function judgedWith(array $findings, DateTimeImmutable $judgedAt): self
    {
        return clone ($this, ['findings' => $findings, 'state' => ReviewState::Judged, 'judgedAt' => $judgedAt]);
    }

    /**
     * @param list<RoundFinding> $marked
     */
    // Rounds judged before the Review module recorded the findings themselves keep only the ids the judge marked.
    public function judgedFromMarksOnly(array $marked, DateTimeImmutable $judgedAt): self
    {
        return clone ($this->judgedWith($marked, $judgedAt), ['findingTextRecorded' => false]);
    }

    /**
     * @param list<RoundFinding> $dropped
     */
    public function withDropped(array $dropped): self
    {
        return clone ($this, ['dropped' => $dropped]);
    }

    public function withTokens(TokenFigures $tokens): self
    {
        return clone ($this, ['tokens' => $tokens]);
    }

    public function withCost(Cost $cost): self
    {
        return clone ($this, ['cost' => $cost]);
    }

    public function endedAs(RoundEnding $ending): self
    {
        return clone ($this, ['ending' => $ending]);
    }

    public function decidedAs(Decision $outcome): self
    {
        return clone ($this, ['outcome' => $outcome]);
    }

    public function markedAs(ReplayMark $mark): self
    {
        return clone ($this, ['mark' => $mark]);
    }
}
