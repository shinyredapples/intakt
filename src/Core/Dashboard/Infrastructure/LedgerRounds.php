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
use Intakt\Core\Dashboard\Domain\RoundSource;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Shadow\Contract\ProjectMode;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Collected per round and joined at the end, because a reviewer's, the judge's or a call's event says nothing about where its round's start sits in the ledger; excluded from the container because LedgerReviewPages builds one per read.
#[Exclude]
final class LedgerRounds
{
    private const array REVIEWER_KINDS = [
        LedgerEventKind::ReviewerStarted,
        LedgerEventKind::VerdictReceived,
        LedgerEventKind::ReviewerFailed,
        LedgerEventKind::ReviewerBlocked,
        LedgerEventKind::CatchUpOwed,
        LedgerEventKind::ReviewerStopped,
    ];

    /** @var array<string, ReviewedRound> */
    private array $started = [];

    /** @var array<string, array<string, ReviewerOutcome>> */
    private array $outcomes = [];

    /** @var array<string, array{findings: list<RoundFinding>, textRecorded: bool}> */
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

        $others = array_filter($this->started, static fn (ReviewedRound $other): bool => $other !== $chosen && $chosen->isOfTheSameReviewAs($other));
        $rounds = [$chosen, ...array_values($others)];
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
            $this->judged += [$round => ['findings' => $this->judgedFindings($payload), 'textRecorded' => is_array($payload['findings'] ?? null)]];

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

        return [$round => new ReviewedRound(new RoundStart($round, $project, $reviewers), new RoundPlace($change, $number, $this->sourceOf($payload)), $startedAt)];
    }

    /**
     * @param array<string, mixed> $payload
     */
    // Null unless all three are non-empty strings, because a start recorded before they existed must not be guessed at.
    private function sourceOf(array $payload): ?RoundSource
    {
        $repository = $payload['repository'] ?? null;
        $base = $payload['base'] ?? null;
        $task = $payload['task'] ?? null;

        if (!is_string($repository) || '' === $repository || !is_string($base) || '' === $base || !is_string($task) || '' === $task) {
            return null;
        }

        return new RoundSource($repository, $base, $task);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<RoundFinding>
     */
    // A marked id keeps its row without its text, because the judge's call is recorded even where the finding's text is not (rounds judged before it was, or an unreadable entry).
    private function judgedFindings(array $payload): array
    {
        $act = $this->strings($payload['act'] ?? null);
        $followUps = $this->strings($payload['follow_ups'] ?? null);
        $readable = array_values(array_filter(array_map($this->readableFinding(...), $this->listOf($payload['findings'] ?? null))));

        return array_map(static fn (RoundFinding $finding): RoundFinding => match (true) {
            in_array($finding->id, $act, true) => $finding->calledBy(JudgeCall::Act),
            in_array($finding->id, $followUps, true) => $finding->calledBy(JudgeCall::FollowUp),
            default => $finding,
        }, [...$readable, ...$this->markedOnly($act, $followUps, $readable)]);
    }

    /**
     * @param list<string>       $act
     * @param list<string>       $followUps
     * @param list<RoundFinding> $readable
     *
     * @return list<RoundFinding>
     */
    private function markedOnly(array $act, array $followUps, array $readable): array
    {
        $readableIds = array_map(static fn (RoundFinding $finding): string => $finding->id, $readable);

        return array_map(static fn (string $id): RoundFinding => new RoundFinding($id, null, null), array_values(array_diff(array_unique([...$act, ...$followUps]), $readableIds)));
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
            LedgerEventKind::VerdictReceived === $kind && is_string($decision) && is_int($findings) => $outcome->answered($decision, $findings, $this->modeOf($payload)),
            LedgerEventKind::ReviewerFailed === $kind && is_string($failure) => $outcome->failed($failure),
            LedgerEventKind::ReviewerBlocked === $kind && is_string($reason) => $outcome->blocked($reason),
            LedgerEventKind::ReviewerStopped === $kind => $outcome->stopped(),
            LedgerEventKind::CatchUpOwed === $kind && is_string($substitute) => $outcome->substitutedBy($substitute),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    // Live when the field is absent or unreadable, because a verdict recorded before the field existed was an ordinary one.
    private function modeOf(array $payload): ProjectMode
    {
        $mode = $payload['mode'] ?? null;

        return is_string($mode) ? ProjectMode::tryFrom($mode) ?? ProjectMode::Live : ProjectMode::Live;
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
