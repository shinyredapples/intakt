<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Dashboard\Domain\JudgeCall;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\ReviewerProgress;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Dashboard\Domain\Timeline;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Core\Review\Contract\CatchUpOwed;
use Intakt\Core\Review\Contract\ChangeAddress;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\CountedQuotes;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\DroppedFinding;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\JudgeDone;
use Intakt\Core\Review\Contract\JudgeDoneFinding;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\ReviewerBlocked;
use Intakt\Core\Review\Contract\ReviewerFailed;
use Intakt\Core\Review\Contract\ReviewerFindingsRecorded;
use Intakt\Core\Review\Contract\ReviewerStarted;
use Intakt\Core\Review\Contract\ReviewerStopped;
use Intakt\Core\Review\Contract\RoundCostList;
use Intakt\Core\Review\Contract\RoundCosts;
use Intakt\Core\Review\Contract\RoundEnding;
use Intakt\Core\Review\Contract\RoundEndingLookup;
use Intakt\Core\Review\Contract\RoundEndings;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictReceived;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Collected per round and joined at the end, because a reviewer's, the judge's or a call's event says nothing about where its round's start sits in the ledger; excluded from the container because LedgerReviewPages builds one per read.
#[Exclude]
final class LedgerRounds
{
    private const string UNRECORDED_REASON = 'Reason not recorded: this verdict was written before Intakt kept the reason of a blocked verdict.';

    /** @var array<string, ReviewedRound> */
    private array $started = [];

    /** @var array<string, array<string, ReviewerOutcome>> */
    private array $outcomes = [];

    /** @var array<string, array{findings: list<RoundFinding>, dropped: list<RoundFinding>, everyRecorded: bool, fallback: ?bool, textRecorded: bool, at: DateTimeImmutable, votes: array<string, Decision>}> */
    private array $judged = [];

    /** @var array<string, ReplayMark> */
    private array $roundMarks = [];

    /** @var array<string, array<string, list<string>>> */
    private array $findingsByReviewer = [];

    // Null for a reviewer whose row was written before reporters' severities were recorded, because such a round must be left out of the reviewer chart, never guessed.
    /** @var array<string, array<string, array<string, string>|null>> */
    private array $severitiesByReviewer = [];

    // Taken on the first unjudged round of this read, because a read's rounds all share one look at the failure transport.
    private ?RoundEndingLookup $lookup = null;

    // Kept beside the tokens counted from them, because a round's cost must come from the rows its tokens come from.
    /** @var list<RecordedEvent> */
    private array $usage = [];

    private readonly LedgerTokens $tokens;

    public function __construct(
        private readonly RoundPayloads $payloads,
        private readonly RoundCosts $costs,
        private readonly ChangeDecision $decision,
        private readonly FailureInWords $failures,
        private readonly RoundEndings $endings,
        private readonly ReplayMarks $marks,
        private readonly CountedQuotes $quotes = new CountedQuotes(),
    ) {
        $this->tokens = new LedgerTokens();
    }

    public function add(RecordedEvent $recorded): void
    {
        $kind = $recorded->event->kind;

        if (LedgerEventKind::UsageRecorded === $kind) {
            $this->addUsage($recorded);

            return;
        }

        if (LedgerEventKind::ReviewStarted === $kind) {
            $this->addStart($recorded);

            return;
        }

        if (LedgerEventKind::JudgeDone === $kind) {
            $this->addJudge($recorded);

            return;
        }

        if (LedgerEventKind::ReviewerFindingsRecorded === $kind) {
            $this->addReviewerFindings($recorded);

            return;
        }

        $this->addReviewerRow($recorded);
    }

    public function reviewOf(string $round): ?Review
    {
        $rounds = $this->membersOf($round);

        return [] === $rounds ? null : $this->reviewOn($rounds, $this->costsOf($rounds));
    }

    // Empty for a round never started readably; known from the starts alone, so a read of one review can learn which rounds to read before it reads them.
    /**
     * @return list<string>
     */
    public function roundsOfTheReviewOf(string $round): array
    {
        return array_map(static fn (ReviewedRound $member): string => $member->round, $this->membersOf($round));
    }

    /**
     * @param list<string> $rounds
     *
     * @return list<string>
     */
    public function callsOf(array $rounds): array
    {
        return $this->tokens->callsOf($rounds);
    }

    // Each review is read from its first round in ledger order and its rounds are skipped after, so a change with several rounds is one review. The ids come from the rounds, not the keys, because PHP turns a key of digits into an integer.
    /**
     * @return list<Review>
     */
    public function reviews(): array
    {
        $grouped = [];
        $seen = [];

        foreach ($this->started as $started) {
            $rounds = isset($seen[$started->round]) ? [] : $this->membersOf($started->round);

            if ([] !== $rounds) {
                $grouped[] = $rounds;
                $seen += array_fill_keys(array_map(static fn (ReviewedRound $member): string => $member->round, $rounds), true);
            }
        }

        if ([] === $grouped) {
            return [];
        }

        $costs = $this->costsOf(array_merge(...$grouped));

        return array_map(fn (array $rounds): Review => $this->reviewOn($rounds, $costs), $grouped);
    }

    // In the ledger order of each round's first readable start, because the review list sorts by it and a later start must not move a round; without cost, tokens or replay mark, because the list shows none of them and each costs a query per round.
    /**
     * @return list<ReviewedRound>
     */
    public function listedInStartOrder(): array
    {
        return array_map($this->decidedAsOnThePage(...), array_values($this->started));
    }

    /**
     * @return list<ReviewedRound>
     */
    private function membersOf(string $round): array
    {
        $chosen = $this->started[$round] ?? null;

        if (!$chosen instanceof ReviewedRound) {
            return [];
        }

        $rounds = $this->oneSequenceOf($chosen, $this->sameReviewAs($chosen));
        usort($rounds, static fn (ReviewedRound $one, ReviewedRound $other): int => ($one->number ?? 0) <=> ($other->number ?? 0));

        return $rounds;
    }

    /**
     * @return non-empty-list<ReviewedRound>
     */
    private function sameReviewAs(ReviewedRound $chosen): array
    {
        $others = array_filter($this->started, static fn (ReviewedRound $other): bool => $other !== $chosen && $chosen->isOfTheSameReviewAs($other));

        return [$chosen, ...array_values($others)];
    }

    // A round number twice among the spellings of one pull request means two spellings were stored apart before the migration to the canonical address (Version20261122100000), each with a numbering of its own; only the chosen round's spelling is then its review, because mixing two numberings would make a legacy round the latest and offer a round number storage does not expect.
    /**
     * @param list<ReviewedRound> $rounds
     *
     * @return list<ReviewedRound>
     */
    private function oneSequenceOf(ReviewedRound $chosen, array $rounds): array
    {
        $numbers = array_filter(array_map(static fn (ReviewedRound $round): ?int => $round->number, $rounds), static fn (?int $number): bool => null !== $number);

        if (count($numbers) === count(array_unique($numbers))) {
            return $rounds;
        }

        return array_values(array_filter($rounds, static fn (ReviewedRound $round): bool => $round->change === $chosen->change));
    }

    private function addUsage(RecordedEvent $recorded): void
    {
        $this->usage[] = $recorded;
        $usage = $this->payloads->usageOf($recorded);

        if (null !== $usage) {
            $this->tokens->add($usage);
        }
    }

    // The first readable start counts, as on the review list, so a repeated start cannot move or change the round.
    private function addStart(RecordedEvent $recorded): void
    {
        $started = $this->payloads->startOf($recorded);

        if ($started instanceof ReviewedRound) {
            $this->started += [$started->round => $started];
        }
    }

    // The Review module records one judge call per round, so a second one could only be a stray row and the first stays.
    private function addJudge(RecordedEvent $recorded): void
    {
        $judge = $this->payloads->judgeOf($recorded);

        if ($judge instanceof JudgeDone) {
            $findings = $this->payloads->judgedFindingsOf($recorded);
            $this->judged += [$judge->round => [
                'findings' => $this->judgedFindings($judge, $findings ?? []),
                'dropped' => $this->droppedFindings($recorded),
                'everyRecorded' => $this->payloads->isEveryJudgedFindingRecorded($recorded, $judge),
                'fallback' => $judge->fallback,
                'textRecorded' => null !== $findings,
                'at' => $recorded->recordedAt,
                'votes' => $judge->votes,
            ]];
        }
    }

    private function addReviewerFindings(RecordedEvent $recorded): void
    {
        $row = $this->payloads->reviewerFindingsOf($recorded);

        if ($row instanceof ReviewerFindingsRecorded) {
            $this->findingsByReviewer[$row->round][$row->reviewer] = [...$row->findings];
            $this->severitiesByReviewer[$row->round][$row->reviewer] = null === $row->severities ? null : array_map(static fn (Severity $severity): string => $severity->value, $row->severities);
        }
    }

    /**
     * @param list<JudgeDoneFinding> $findings
     *
     * @return list<RoundFinding>
     */
    // A marked id keeps its row without its text, because the judge's call is recorded even where the finding's text is not (rounds judged before it was, or an unreadable entry).
    private function judgedFindings(JudgeDone $judge, array $findings): array
    {
        $recorded = array_map(static fn (JudgeDoneFinding $finding): RoundFinding => new RoundFinding($finding->id, $finding->severity->value, $finding->text, [...$finding->merged], $finding->file, $finding->line), $findings);

        return array_map(static fn (RoundFinding $finding): RoundFinding => match (true) {
            $judge->act->contains($finding->id) => $finding->calledBy(JudgeCall::Act),
            $judge->followUps->contains($finding->id) => $finding->calledBy(JudgeCall::FollowUp),
            default => $finding,
        }, [...$this->entriesOf($recorded), ...$this->markedOnly($judge, $recorded)]);
    }

    /**
     * @return list<RoundFinding>
     */
    private function droppedFindings(RecordedEvent $recorded): array
    {
        // The quotes come from the quote check itself, never from the card's own reading of the text, so the list is the one the recorded reason counted.
        return array_map(fn (DroppedFinding $finding): RoundFinding => new RoundFinding($finding->id, $finding->severity->value, $finding->text, [], $finding->file, $finding->line, $finding->reason, $this->quotes->of($finding->text), $finding->nearest), $this->payloads->droppedOf($recorded));
    }

    /**
     * @param list<RoundFinding> $recorded
     *
     * @return list<RoundFinding>
     */
    // A finding an act entry stands for gets no row of its own, because the act list names each place once.
    private function entriesOf(array $recorded): array
    {
        $merged = array_merge([], ...array_map(static fn (RoundFinding $finding): array => $finding->merged, $recorded));

        return array_values(array_filter($recorded, static fn (RoundFinding $finding): bool => !in_array($finding->id, $merged, true)));
    }

    /**
     * @param list<RoundFinding> $recorded
     *
     * @return list<RoundFinding>
     */
    private function markedOnly(JudgeDone $judge, array $recorded): array
    {
        $recordedIds = array_map(static fn (RoundFinding $finding): string => $finding->id, $recorded);

        return array_map(static fn (string $id): RoundFinding => new RoundFinding($id, null, null), array_values(array_diff(array_unique([...$judge->act, ...$judge->followUps]), $recordedIds)));
    }

    private function addReviewerRow(RecordedEvent $recorded): void
    {
        $row = $this->payloads->reviewerRowOf($recorded);

        if (null !== $row) {
            $outcome = $this->outcomes[$row->round][$row->reviewer] ?? new ReviewerOutcome($row->reviewer, $row->role, $row->tier->value, $recorded->recordedAt);
            $this->outcomes[$row->round][$row->reviewer] = $this->moved($outcome, $row, $recorded->recordedAt);
        }
    }

    private function moved(ReviewerOutcome $outcome, ReviewerStarted|VerdictReceived|ReviewerFailed|ReviewerBlocked|ReviewerStopped|CatchUpOwed $row, DateTimeImmutable $at): ReviewerOutcome
    {
        return match (true) {
            $row instanceof ReviewerStarted => $outcome->restarted($at, $row->model),
            $row instanceof VerdictReceived => $outcome->answered($row->decision, $row->findings, $row->mode)->because($row->reason ?? $this->unrecordedReasonOf($row->decision)),
            $row instanceof ReviewerFailed => $outcome->failed($this->failures->of($row->failure)),
            $row instanceof ReviewerBlocked => $outcome->blocked($row->reason),
            $row instanceof ReviewerStopped => $outcome->stopped(),
            $row instanceof CatchUpOwed => $outcome->substitutedBy($row->substitute->value, $row->model),
        };
    }

    // A blocked verdict recorded before reasons were kept still says so, because Blocked must never show without a word on why.
    private function unrecordedReasonOf(Decision $decision): ?string
    {
        return Decision::Blocked === $decision ? self::UNRECORDED_REASON : null;
    }

    // Priced together, because each pricing goes through every usage row of the read.
    /**
     * @param list<ReviewedRound> $rounds
     */
    private function costsOf(array $rounds): RoundCostList
    {
        return $this->costs->costsIn(new RecordedEvents($this->usage), ...array_map(static fn (ReviewedRound $round): string => $round->round, $rounds));
    }

    /**
     * @param non-empty-list<ReviewedRound> $rounds
     */
    private function reviewOn(array $rounds, RoundCostList $costs): Review
    {
        return new Review(array_map(fn (ReviewedRound $member): ReviewedRound => $this->completed($member, $costs), $rounds), $this->timelineOf($rounds));
    }

    private function completed(ReviewedRound $round, RoundCostList $costs): ReviewedRound
    {
        return $this->decidedAsOnThePage($round->withTokens($this->tokens->ofRound($round->round))->withCost($costs->of($round->round))->markedAs($this->markOf($round)));
    }

    // A spelling other than its canonical form, kept apart from rounds of its pull request, is history only, because storage numbers every new round of that pull request on the canonical row.
    /**
     * @param non-empty-list<ReviewedRound> $members
     */
    private function timelineOf(array $members): Timeline
    {
        $member = $members[0];
        $keptApart = $member->change !== ChangeAddress::canonical($member->change) && count($members) < count($this->sameReviewAs($member));

        return $keptApart ? Timeline::HistoryOnly : Timeline::Current;
    }

    // Each round keeps its own mark, never one of its review, because a replay round opened after a live round of the same change is still a replay; kept per round for one read, because the board and a review page may ask for the same round more than once.
    private function markOf(ReviewedRound $round): ReplayMark
    {
        return $this->roundMarks[$round->round] ??= $this->marks->ofRound($round->round, $round->project);
    }

    // The one place that decides a round's outcomes, findings, ending and state, so the review list and the review page never decide them apart.
    private function decidedAsOnThePage(ReviewedRound $round): ReviewedRound
    {
        $completed = $this->withReporters($round->withOutcomes(array_values($this->outcomes[$round->round] ?? [])));

        $judged = $this->judged[$round->round] ?? null;

        $completed = match ($judged['fallback'] ?? null) {
            true => $completed->judgedByFallback(),
            false => $completed->judgedOnItsOwnMarks(),
            null => $completed,
        };

        if (null !== $judged && !$judged['everyRecorded']) {
            $completed = $completed->withFindingsMissing();
        }

        return match (true) {
            null === $judged => $this->endedOrRunning($completed),
            $judged['textRecorded'] => $this->decided($completed->judgedWith($judged['findings'], $judged['at'])->withDropped($judged['dropped']), $judged['votes']),
            default => $this->decided($completed->judgedFromMarksOnly($judged['findings'], $judged['at'])->withDropped($judged['dropped']), $judged['votes']),
        };
    }

    // Left unrecorded unless every reviewer with a verdict has a readable row, because a round completed before the rows were written, or one whose row cannot be read, has no full record, and a partial one would turn a finding two reviewers reported into a one-reviewer catch.
    private function withReporters(ReviewedRound $round): ReviewedRound
    {
        $reported = $this->findingsByReviewer[$round->round] ?? null;

        if (null === $reported) {
            return $round;
        }

        $answered = array_filter($round->outcomes, static fn (ReviewerOutcome $outcome): bool => ReviewerProgress::Answered === $outcome->progress);
        $unrecorded = array_filter($answered, static fn (ReviewerOutcome $outcome): bool => !array_key_exists($outcome->id, $reported));

        if ([] !== $unrecorded) {
            return $round;
        }

        $severities = $this->severitiesByReviewer[$round->round] ?? [];
        $rated = array_filter($severities, static fn (?array $rated): bool => null !== $rated);

        return count($rated) === count($reported) ? $round->withFindingsByReviewer($reported)->withSeveritiesByReviewer($rated) : $round->withFindingsByReviewer($reported);
    }

    private function endedOrRunning(ReviewedRound $round): ReviewedRound
    {
        $this->lookup ??= $this->endings->lookup();
        $ending = $this->lookup->ofUnjudged($round->round, $round->reviewers, $round->startedAt);

        return $ending instanceof RoundEnding ? $round->endedAs($ending) : $round;
    }

    // Only a judged round with a recorded quorum gets an outcome, because the console decides once the judge is done and a policy must never be guessed.
    /**
     * @param array<string, Decision> $counted
     */
    private function decided(ReviewedRound $round, array $counted): ReviewedRound
    {
        if (!$round->quorum instanceof QuorumPolicy) {
            return $round;
        }

        $votes = array_map(static fn (ReviewerOutcome $outcome): PanelVerdict => new PanelVerdict(Tier::from($outcome->tier), new Verdict(self::voteOf($outcome, $counted), [], [], [])), $round->outcomes);
        $unstarted = array_fill(0, max(0, $round->reviewers - count($votes)), new PanelVerdict(Tier::Standard, new Verdict(Decision::Blocked, [], [], [])));

        return $round->decidedAs($this->decision->of($round->quorum, [...$votes, ...$unstarted]));
    }

    // A reviewer without a verdict votes as blocked, as in the Review module, so a failed or unstarted reviewer never helps a quorum; an answer counts as the judge step recorded it, as the console counts it.
    /**
     * @param array<string, Decision> $counted
     */
    private static function voteOf(ReviewerOutcome $outcome, array $counted): Decision
    {
        if (ReviewerProgress::Answered !== $outcome->progress) {
            return Decision::Blocked;
        }

        return $counted[$outcome->id] ?? $outcome->decision ?? Decision::Blocked;
    }
}
