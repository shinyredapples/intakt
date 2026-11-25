<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Review\Contract\ChangeAddress;
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

    // False when the judge's row holds a finding entry that cannot be read, or marks an id its findings do not hold, so a count over the round never reads a lost finding as none.
    public bool $everyFindingRecorded;

    // True when the judge left a finding without its mark (the fallback for an answer that cannot be read), null for a row written before that was recorded, so a count of the judge's own calls can leave both out.
    public ?bool $judgeFellBack;
    public ReviewState $state;
    public TokenFigures $tokens;
    public Cost $cost;
    public ?Decision $outcome;
    public ?DateTimeImmutable $judgedAt;
    public ?ReplayMark $mark;

    // Null for a round completed before the ledger recorded who reported what, because such a round must read as unknown, never as a round whose reviewers reported nothing.
    /** @var array<string, list<string>>|null */
    public ?array $findingsByReviewer;

    // Keyed by reviewer id, then finding id, with the severity that reviewer gave, because two reviewers may repeat one tracked finding at different severities; null unless every reporter row recorded them.
    /** @var array<string, array<string, string>>|null */
    public ?array $severitiesByReviewer;

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
        $this->findingTextRecorded = true;
        $this->everyFindingRecorded = true;
        $this->judgeFellBack = null;
        $this->state = ReviewState::InProgress;
        $this->tokens = TokenFigures::none();
        $this->cost = Cost::none();
        $this->outcome = null;
        $this->judgedAt = null;
        $this->mark = null;
        $this->findingsByReviewer = null;
        $this->severitiesByReviewer = null;
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

    public function finding(string $id): ?RoundFinding
    {
        return $this->findingIn($this, $id);
    }

    // The reviewers whose reports the finding holds, merged ones included; null for a round completed before the ledger recorded who reported what, so the page never claims nobody reported it.
    /**
     * @return list<ReviewerOutcome>|null
     */
    public function reportersOf(RoundFinding $finding): ?array
    {
        return $this->reportersIn($this, $finding);
    }

    // Null when the round holds no severity from this reviewer for the id, such as a reporter row written before severities were recorded, so a count never guesses one.
    public function severityReportedBy(string $reviewer, string $id): ?string
    {
        return $this->severityIn($this, $reviewer, $id);
    }

    // Every severity the reviewer gave under any id the finding holds, because a reviewer may have reported only a merged one, or two that the judge called the same at different severities.
    /**
     * @return list<string>
     */
    public function severitiesReportedOf(string $reviewer, RoundFinding $finding): array
    {
        $given = array_map(fn (string $id): ?string => $this->severityReportedBy($reviewer, $id), $finding->ids());

        return array_values(array_unique(array_filter($given, static fn (?string $severity): bool => null !== $severity)));
    }

    // One report per reviewer and per id of the finding that reviewer reported, because the judge's card counts reports, not ids; null for a round completed before the ledger recorded who reported what, so the page never claims a count it does not know.
    public function reportCountOf(RoundFinding $finding): ?int
    {
        return $this->reportCountIn($this, $finding);
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

    // Only the reviewers with a recorded start, because one blocked before it started never ran in this round.
    /**
     * @return list<ReviewerOutcome>
     */
    public function startedOutcomes(): array
    {
        return [...array_filter($this->outcomes, static fn (ReviewerOutcome $outcome): bool => true === $outcome->started)];
    }

    // Verdicts only, because failed and blocked reviewers give no opinion.
    public function agreement(): string
    {
        $verdicts = $this->verdictsGivenIn($this);
        $panel = max($this->reviewers, count($this->outcomesIn($this)));

        if ([] === $verdicts) {
            return '';
        }

        $kinds = array_values(array_unique(array_map(static fn (ReviewOutcome $outcome): string => lcfirst($outcome->plainWords()), $verdicts)));

        if (1 !== count($kinds)) {
            return 'The reviewers disagree';
        }

        return match (true) {
            1 === $panel => 'The reviewer says '.$kinds[0],
            count($verdicts) < $panel => count($verdicts).' of '.$panel.(1 === count($verdicts) ? ' says ' : ' say ').$kinds[0],
            2 === $panel => 'Both say '.$kinds[0],
            default => 'All '.$panel.' say '.$kinds[0],
        };
    }

    public function failedReviewers(): int
    {
        return count(array_filter($this->outcomes, static fn (ReviewerOutcome $outcome): bool => ReviewerProgress::Failed === $outcome->progress));
    }

    // A round without a change name has no siblings it could be told apart from, so it stands alone. Compared in the canonical form, because the append-only start rows of a pull request's older rounds may keep another spelling than its newer ones.
    public function isOfTheSameReviewAs(self $other): bool
    {
        if ('' === $this->change || '' === $other->change) {
            return $this->round === $other->round;
        }

        return $this->project === $other->project && ChangeAddress::sameChange($this->change, $other->change);
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

    public function withFindingsMissing(): self
    {
        return clone ($this, ['everyFindingRecorded' => false]);
    }

    public function judgedByFallback(): self
    {
        return clone ($this, ['judgeFellBack' => true]);
    }

    public function judgedOnItsOwnMarks(): self
    {
        return clone ($this, ['judgeFellBack' => false]);
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

    /**
     * @param array<string, array<string, string>> $severities
     */
    public function withSeveritiesByReviewer(array $severities): self
    {
        return clone ($this, ['severitiesByReviewer' => $severities]);
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

    /**
     * @return list<ReviewOutcome>
     */
    private function verdictsGivenIn(self $round): array
    {
        $verdicts = [];

        foreach ($this->outcomesIn($round) as $reviewer) {
            $outcome = $reviewer->outcome();

            if (ReviewOutcome::Approved === $outcome || ReviewOutcome::ChangesRequested === $outcome) {
                $verdicts[] = $outcome;
            }
        }

        return $verdicts;
    }

    // Read through a round for the same reason as `isReplayMarked()`: PHPStan keeps the constructor's empty list for `$this->outcomes`.
    /**
     * @return list<ReviewerOutcome>
     */
    private function outcomesIn(self $round): array
    {
        return $round->outcomes;
    }

    // Read through a round for the same reason as `isReplayMarked()`: PHPStan keeps the constructor's empty list for `$this->findings`.
    private function findingIn(self $round, string $id): ?RoundFinding
    {
        return array_find($round->findings, static fn (RoundFinding $finding): bool => $finding->id === $id);
    }

    /**
     * @return list<ReviewerOutcome>|null
     */
    private function reportersIn(self $round, RoundFinding $finding): ?array
    {
        $reports = $round->findingsByReviewer;

        if (null === $reports) {
            return null;
        }

        return array_values(array_filter($round->outcomes, static fn (ReviewerOutcome $outcome): bool => [] !== array_intersect($finding->ids(), $reports[$outcome->id] ?? [])));
    }

    // Read through a round for the same reason as `isReplayMarked()`: PHPStan keeps the constructor's null for `$this->severitiesByReviewer`.
    private function severityIn(self $round, string $reviewer, string $id): ?string
    {
        return $round->severitiesByReviewer[$reviewer][$id] ?? null;
    }

    // Read through a round for the same reason as `isReplayMarked()`: PHPStan keeps the constructor's null for `$this->findingsByReviewer`.
    private function reportCountIn(self $round, RoundFinding $finding): ?int
    {
        $reports = $round->findingsByReviewer;

        if (null === $reports) {
            return null;
        }

        return array_sum(array_map(static fn (array $reported): int => count(array_intersect($finding->ids(), array_unique($reported))), $reports));
    }
}
