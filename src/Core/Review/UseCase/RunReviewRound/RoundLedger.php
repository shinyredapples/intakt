<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FollowUp;
use Intakt\Core\Review\Contract\JudgedFindings;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundReviewer;

// Called inside the caller's transaction, so a refused event rolls the move it reports back with it.
final readonly class RoundLedger
{
    public function __construct(private Ledger $ledger)
    {
    }

    public function reviewStarted(ReviewRound $round): void
    {
        $this->append(LedgerEventKind::ReviewStarted, [
            'round' => $round->id()->toRfc4122(),
            'project' => $round->project(),
            'reviewers' => count($round->reviewers()),
            'change' => $round->change(),
            'number' => $round->number(),
        ]);
    }

    public function reviewerStarted(RoundReviewer $reviewer): void
    {
        $this->append(LedgerEventKind::ReviewerStarted, $this->identify($reviewer));
    }

    public function verdictReceived(RoundReviewer $reviewer, Verdict $verdict): void
    {
        $this->append(LedgerEventKind::VerdictReceived, $this->identify($reviewer) + ['decision' => $verdict->verdict->value, 'findings' => count($verdict->findings)]);
    }

    // Written with the verdict in one transaction, so a substitute's verdict never counts without its owed catch-up.
    public function catchUpOwed(RoundReviewer $reviewer, Provider $substitute): void
    {
        $this->append(LedgerEventKind::CatchUpOwed, $this->identify($reviewer) + ['substitute' => $substitute->value]);
    }

    public function reviewerFailed(RoundReviewer $reviewer, string $failure): void
    {
        $this->append(LedgerEventKind::ReviewerFailed, $this->identify($reviewer) + ['failure' => $failure]);
    }

    public function reviewerBlocked(RoundReviewer $reviewer, string $reason): void
    {
        $this->append(LedgerEventKind::ReviewerBlocked, $this->identify($reviewer) + ['reason' => $reason]);
    }

    // The judged findings go with the call, because the dashboard renders from the ledger alone and needs what each act id stands for.
    public function judgeDone(ReviewRound $round, JudgedFindings $judged): void
    {
        $this->append(LedgerEventKind::JudgeDone, [
            'round' => $round->id()->toRfc4122(),
            'act' => array_map(static fn (Finding $finding): string => $finding->id, $judged->actList),
            'follow_ups' => array_map(static fn (FollowUp $followUp): string => $followUp->finding->id, $judged->followUps),
            'findings' => array_map(static fn (Finding $finding): array => ['id' => $finding->id, 'severity' => $finding->severity->value, 'text' => $finding->text], $judged->findings),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function identify(RoundReviewer $reviewer): array
    {
        return ['round' => $reviewer->round()->id()->toRfc4122(), 'reviewer' => $reviewer->id()->toRfc4122(), 'role' => $reviewer->roleName(), 'tier' => $reviewer->tier()->value];
    }

    /**
     * @param array<string, int|string|list<string>|list<array<string, string>>> $payload
     */
    private function append(LedgerEventKind $kind, array $payload): void
    {
        $result = $this->ledger->append(new LedgerEvent($kind, json_encode($payload, JSON_THROW_ON_ERROR)));

        if (!$result->succeeded()) {
            throw ReviewEventNotRecorded::for($kind->value, (string) $result->failure);
        }
    }
}
