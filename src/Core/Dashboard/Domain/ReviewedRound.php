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

    // The findings an act entry merged, which get no row of their own, so a reviewer's finding keeps the severity it reported.
    /** @var list<RoundFinding> */
    public array $mergedFindings;

    public bool $findingTextRecorded;
    public ReviewState $state;
    public TokenFigures $tokens;
    public Cost $cost;
    public ?Decision $outcome;
    public ?DateTimeImmutable $judgedAt;
    public ?ReplayMark $mark;

    // Null for a round completed before the ledger recorded who reported what, because such a round must read as unknown, never as a round whose reviewers reported nothing.
    /** @var array<string, list<string>>|null */
    public ?array $findingsByReviewer;

    // The mark is null until the round is read with its review, because the start row does not say whether the round is a replay. The speed factor is null for every round but a demo replay that stored one.
    public function __construct(
        RoundStart $start,
        RoundPlace $place,
        public DateTimeImmutable $startedAt,
        public ?QuorumPolicy $quorum,
        public ?RoundEnding $ending = null,
        public ?float $speedFactor = null,
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
        $this->mergedFindings = [];
        $this->findingTextRecorded = true;
        $this->state = ReviewState::InProgress;
        $this->tokens = TokenFigures::none();
        $this->cost = Cost::none();
        $this->outcome = null;
        $this->judgedAt = null;
        $this->mark = null;
        $this->findingsByReviewer = null;
    }

    // A replay shows like a live round, so the pages mark it, because its verdicts were never given on the change.
    public function isReplay(): bool
    {
        return $this->isReplayMarked($this);
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

    // Null for an id the round holds no severity for, such as a mark kept without its finding, so a count never guesses one.
    public function severityOf(string $id): ?string
    {
        return $this->findingOf($this, $id)?->severity;
    }

    public function reviewOutcome(): ?ReviewOutcome
    {
        return ReviewOutcome::of($this);
    }

    // The quorum's own decision in the page's words, apart from the round's outcome, because a round whose reviewers all failed still has a quorum that blocked it.
    public function quorumOutcome(): ?ReviewOutcome
    {
        return ReviewOutcome::ofQuorum($this);
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

    /**
     * @param list<RoundFinding> $merged
     */
    public function withMergedFindings(array $merged): self
    {
        return clone ($this, ['mergedFindings' => $merged]);
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

    // Keyed by reviewer id, with the ids of the change's finding ledger, because those are the ids the act entries and their merges name.
    /**
     * @param array<string, list<string>> $findings
     */
    public function withFindingsByReviewer(array $findings): self
    {
        return clone ($this, ['findingsByReviewer' => $findings]);
    }

    public function markedAs(ReplayMark $mark): self
    {
        return clone ($this, ['mark' => $mark]);
    }

    // Read through a round rather than `$this`, because PHPStan keeps the constructor's null for `$this->mark` although `markedAs()` sets it (upstream/phpstan-clone-with-readonly-constructor-type).
    private function isReplayMarked(self $round): bool
    {
        return ReplayMark::Replay === $round->mark;
    }

    // Read through a round for the same reason as `isReplayMarked()`: PHPStan keeps the constructor's empty lists for `$this`.
    private function findingOf(self $round, string $id): ?RoundFinding
    {
        return array_find([...$round->findings, ...$round->mergedFindings, ...$round->dropped], static fn (RoundFinding $finding): bool => $finding->id === $id);
    }
}
