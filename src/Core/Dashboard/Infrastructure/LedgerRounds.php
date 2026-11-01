<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Domain\JudgeCall;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;

// Collected per round and joined at the end, because a reviewer's, the judge's or a call's event says nothing about where its round's start sits in the ledger.
final class LedgerRounds
{
    private const array REVIEWER_KINDS = [
        LedgerEventKind::ReviewerStarted,
        LedgerEventKind::VerdictReceived,
        LedgerEventKind::ReviewerFailed,
        LedgerEventKind::ReviewerBlocked,
        LedgerEventKind::CatchUpOwed,
    ];

    /** @var array<string, ReviewedRound> */
    private array $started = [];

    /** @var array<string, array<string, ReviewerOutcome>> */
    private array $outcomes = [];

    /** @var array<string, list<RoundFinding>> */
    private array $judged = [];

    private readonly LedgerTokens $tokens;

    public function __construct()
    {
        $this->tokens = new LedgerTokens();
    }

    public function add(RecordedEvent $recorded): void
    {
        $payload = LedgerPayload::of($recorded);

        if (LedgerEventKind::UsageRecorded === $recorded->event->kind) {
            $this->tokens->add($payload);

            return;
        }

        $round = $payload['round'] ?? null;

        if (is_string($round)) {
            $this->addToRound($recorded, $round, $payload);
        }
    }

    public function reviewOf(string $round): ?Review
    {
        $chosen = $this->started[$round] ?? null;

        if (!$chosen instanceof ReviewedRound) {
            return null;
        }

        $rounds = array_values(array_filter($this->started, $chosen->isOfTheSameReviewAs(...)));
        usort($rounds, static fn (ReviewedRound $one, ReviewedRound $other): int => ($one->number ?? 0) <=> ($other->number ?? 0));

        return new Review(array_map($this->completed(...), $rounds));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function addToRound(RecordedEvent $recorded, string $round, array $payload): void
    {
        $kind = $recorded->event->kind;

        if (LedgerEventKind::ReviewStarted === $kind) {
            $this->started += $this->readableStart($round, $payload, $recorded->recordedAt);

            return;
        }

        // The Review module records one judge call per round, so a second one could only be a stray row and the first stays.
        if (LedgerEventKind::JudgeDone === $kind) {
            $this->judged += [$round => $this->judgedFindings($payload)];

            return;
        }

        if (in_array($kind, self::REVIEWER_KINDS, true)) {
            $this->addReviewerEvent($kind, $round, $payload);
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, ReviewedRound>
     */
    // The first readable start counts, as on the review list, so a repeated start cannot move or change the round.
    private function readableStart(string $round, array $payload, DateTimeImmutable $startedAt): array
    {
        $project = $payload['project'] ?? null;
        $reviewers = $payload['reviewers'] ?? null;
        $change = $payload['change'] ?? '';
        $number = $payload['number'] ?? null;

        if (!is_string($project) || !is_int($reviewers) || !is_string($change) || null !== $number && !is_int($number)) {
            return [];
        }

        return [$round => new ReviewedRound(new RoundStart($round, $project, $reviewers), new RoundPlace($change, $number), $startedAt)];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<RoundFinding>
     */
    private function judgedFindings(array $payload): array
    {
        $act = $this->strings($payload['act'] ?? null);
        $followUps = $this->strings($payload['follow_ups'] ?? null);
        $findings = [];

        foreach ($this->listOf($payload['findings'] ?? null) as $entry) {
            $finding = $this->readableFinding($entry);

            if ($finding instanceof RoundFinding) {
                $findings[] = match (true) {
                    in_array($finding->id, $act, true) => $finding->calledBy(JudgeCall::Act),
                    in_array($finding->id, $followUps, true) => $finding->calledBy(JudgeCall::FollowUp),
                    default => $finding,
                };
            }
        }

        return $findings;
    }

    private function readableFinding(mixed $entry): ?RoundFinding
    {
        $id = is_array($entry) ? ($entry['id'] ?? null) : null;
        $severity = is_array($entry) ? ($entry['severity'] ?? null) : null;
        $text = is_array($entry) ? ($entry['text'] ?? null) : null;

        return is_string($id) && is_string($severity) && is_string($text) ? new RoundFinding($id, $severity, $text) : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function addReviewerEvent(LedgerEventKind $kind, string $round, array $payload): void
    {
        $outcome = $this->outcomeOf($round, $payload);

        if (!$outcome instanceof ReviewerOutcome) {
            return;
        }

        $this->outcomes[$round][$outcome->id] = $this->moved($outcome, $kind, $payload) ?? $outcome;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function outcomeOf(string $round, array $payload): ?ReviewerOutcome
    {
        $reviewer = $payload['reviewer'] ?? null;
        $role = $payload['role'] ?? null;
        $tier = $payload['tier'] ?? null;

        if (!is_string($reviewer) || !is_string($role) || !is_string($tier)) {
            return null;
        }

        return $this->outcomes[$round][$reviewer] ?? new ReviewerOutcome($reviewer, $role, $tier);
    }

    /**
     * @param array<string, mixed> $payload
     */
    // Null when the payload lacks what its kind needs, so the reviewer keeps the row it had.
    private function moved(ReviewerOutcome $outcome, LedgerEventKind $kind, array $payload): ?ReviewerOutcome
    {
        $decision = $payload['decision'] ?? null;
        $findings = $payload['findings'] ?? null;
        $failure = $payload['failure'] ?? null;
        $reason = $payload['reason'] ?? null;
        $substitute = $payload['substitute'] ?? null;

        return match (true) {
            LedgerEventKind::ReviewerStarted === $kind => $outcome->restarted(),
            LedgerEventKind::VerdictReceived === $kind && is_string($decision) && is_int($findings) => $outcome->answered($decision, $findings),
            LedgerEventKind::ReviewerFailed === $kind && is_string($failure) => $outcome->failed($failure),
            LedgerEventKind::ReviewerBlocked === $kind && is_string($reason) => $outcome->blocked($reason),
            LedgerEventKind::CatchUpOwed === $kind && is_string($substitute) => $outcome->substitutedBy($substitute),
            default => null,
        };
    }

    private function completed(ReviewedRound $round): ReviewedRound
    {
        $completed = $round->withOutcomes(array_values($this->outcomes[$round->round] ?? []))->withTokens($this->tokens->ofRound($round->round));

        return array_key_exists($round->round, $this->judged) ? $completed->judgedWith($this->judged[$round->round]) : $completed;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $values): array
    {
        return array_values(array_filter($this->listOf($values), is_string(...)));
    }

    /**
     * @return list<mixed>
     */
    private function listOf(mixed $values): array
    {
        return is_array($values) ? array_values($values) : [];
    }
}
