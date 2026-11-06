<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\JudgeCall;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\CatchUpOwed;
use Intakt\Core\Review\Contract\JudgeDone;
use Intakt\Core\Review\Contract\JudgeDoneFinding;
use Intakt\Core\Review\Contract\ReviewerBlocked;
use Intakt\Core\Review\Contract\ReviewerFailed;
use Intakt\Core\Review\Contract\ReviewerStarted;
use Intakt\Core\Review\Contract\ReviewerStopped;
use Intakt\Core\Review\Contract\VerdictReceived;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Collected per round and joined at the end, because a reviewer's, the judge's or a call's event says nothing about where its round's start sits in the ledger; excluded from the container because LedgerReviewPages builds one per read.
#[Exclude]
final class LedgerRounds
{
    /** @var array<string, ReviewedRound> */
    private array $started = [];

    /** @var array<string, array<string, ReviewerOutcome>> */
    private array $outcomes = [];

    /** @var array<string, array{findings: list<RoundFinding>, textRecorded: bool}> */
    private array $judged = [];

    private readonly LedgerTokens $tokens;

    public function __construct(private readonly RoundPayloads $payloads)
    {
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

        $this->addReviewerRow($recorded);
    }

    public function reviewOf(string $round): ?Review
    {
        $chosen = $this->started[$round] ?? null;

        if (!$chosen instanceof ReviewedRound) {
            return null;
        }

        $others = array_filter($this->started, static fn (ReviewedRound $other): bool => $other !== $chosen && $chosen->isOfTheSameReviewAs($other));
        $rounds = [$chosen, ...array_values($others)];
        usort($rounds, static fn (ReviewedRound $one, ReviewedRound $other): int => ($one->number ?? 0) <=> ($other->number ?? 0));

        return new Review(array_map($this->completed(...), $rounds));
    }

    private function addUsage(RecordedEvent $recorded): void
    {
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
        $withText = $this->payloads->judgeWithTextOf($recorded);
        $judge = $withText ?? $this->payloads->judgeOf($recorded);

        if ($judge instanceof JudgeDone) {
            $this->judged += [$judge->round => ['findings' => $this->judgedFindings($judge), 'textRecorded' => $withText instanceof JudgeDone]];
        }
    }

    /**
     * @return list<RoundFinding>
     */
    // A marked id keeps its row without its text, because the judge's call is recorded even where the finding's text is not (rounds judged before it was).
    private function judgedFindings(JudgeDone $judge): array
    {
        $recorded = array_map(static fn (JudgeDoneFinding $finding): RoundFinding => new RoundFinding($finding->id, $finding->severity->value, $finding->text), $judge->findings);

        return array_map(static fn (RoundFinding $finding): RoundFinding => match (true) {
            in_array($finding->id, $judge->act, true) => $finding->calledBy(JudgeCall::Act),
            in_array($finding->id, $judge->followUps, true) => $finding->calledBy(JudgeCall::FollowUp),
            default => $finding,
        }, [...$recorded, ...$this->markedOnly($judge, $recorded)]);
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
            $outcome = $this->outcomes[$row->round][$row->reviewer] ?? new ReviewerOutcome($row->reviewer, $row->role, $row->tier->value);
            $this->outcomes[$row->round][$row->reviewer] = $this->moved($outcome, $row);
        }
    }

    private function moved(ReviewerOutcome $outcome, ReviewerStarted|VerdictReceived|ReviewerFailed|ReviewerBlocked|ReviewerStopped|CatchUpOwed $row): ReviewerOutcome
    {
        return match (true) {
            $row instanceof ReviewerStarted => $outcome->restarted(),
            $row instanceof VerdictReceived => $outcome->answered($row->decision->value, $row->findings, $row->mode),
            $row instanceof ReviewerFailed => $outcome->failed($row->failure),
            $row instanceof ReviewerBlocked => $outcome->blocked($row->reason),
            $row instanceof ReviewerStopped => $outcome->stopped(),
            $row instanceof CatchUpOwed => $outcome->substitutedBy($row->substitute->value),
        };
    }

    private function completed(ReviewedRound $round): ReviewedRound
    {
        $completed = $round->withOutcomes(array_values($this->outcomes[$round->round] ?? []))->withTokens($this->tokens->ofRound($round->round));

        $judged = $this->judged[$round->round] ?? null;

        return match (true) {
            null === $judged => $completed,
            $judged['textRecorded'] => $completed->judgedWith($judged['findings']),
            default => $completed->judgedFromMarksOnly($judged['findings']),
        };
    }
}
