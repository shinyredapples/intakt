<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use DateTimeImmutable;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Symfony\Component\Uid\Uuid;

final class DashboardLedger implements Ledger
{
    /** @var list<RecordedEvent> */
    private array $recorded = [];

    public function append(LedgerEvent $event): AppendResult
    {
        $this->recorded[] = new RecordedEvent(Uuid::v7(), $event, new DateTimeImmutable('2026-10-28T12:00:00+00:00'));

        return AppendResult::appended(Uuid::v7());
    }

    public function readInOrder(): RecordedEvents
    {
        return new RecordedEvents($this->recorded);
    }

    public function readOfKind(LedgerEventKind $kind): RecordedEvents
    {
        return new RecordedEvents(array_values(array_filter($this->recorded, static fn (RecordedEvent $recorded): bool => $kind === $recorded->event->kind)));
    }

    public function startRound(string $round, string $project): void
    {
        $this->add(LedgerEventKind::ReviewStarted, ['round' => $round, 'project' => $project, 'reviewers' => 2]);
    }

    public function judgeRound(string $round): void
    {
        $this->add(LedgerEventKind::JudgeDone, ['round' => $round, 'act' => [], 'follow_ups' => []]);
    }

    public function startRoundOf(string $change, string $round, int $number): void
    {
        $this->add(LedgerEventKind::ReviewStarted, ['round' => $round, 'project' => 'alpha', 'reviewers' => 2, 'change' => $change, 'number' => $number]);
    }

    public function startRecordedRoundOf(string $change, string $round, int $number): void
    {
        $this->add(LedgerEventKind::ReviewStarted, ['round' => $round, 'project' => 'alpha', 'reviewers' => 2, 'change' => $change, 'number' => $number, 'repository' => '/repos/alpha', 'base' => 'main', 'task' => 'Add the thing.']);
    }

    public function startReviewer(string $round, string $role): void
    {
        $this->add(LedgerEventKind::ReviewerStarted, $this->reviewer($round, $role));
    }

    public function answer(string $round, string $role, int $findings): void
    {
        $this->add(LedgerEventKind::VerdictReceived, $this->reviewer($round, $role) + ['decision' => 0 === $findings ? 'approve' : 'changes', 'findings' => $findings]);
    }

    public function answerInShadow(string $round, string $role): void
    {
        $this->add(LedgerEventKind::VerdictReceived, $this->reviewer($round, $role) + ['decision' => 'approve', 'findings' => 0, 'mode' => 'shadow']);
    }

    public function failReviewer(string $round, string $role, string $failure): void
    {
        $this->add(LedgerEventKind::ReviewerFailed, $this->reviewer($round, $role) + ['failure' => $failure]);
    }

    public function owesCatchUp(string $round, string $role): void
    {
        $this->add(LedgerEventKind::CatchUpOwed, $this->reviewer($round, $role) + ['substitute' => 'openai']);
    }

    /**
     * @param array<string, 'act'|'defer'|'reject'> $marks
     */
    public function judgeFindings(string $round, array $marks): void
    {
        $this->add(LedgerEventKind::JudgeDone, [
            'round' => $round,
            'act' => array_keys(array_filter($marks, static fn (string $mark): bool => 'act' === $mark)),
            'follow_ups' => array_keys(array_filter($marks, static fn (string $mark): bool => 'defer' === $mark)),
            'findings' => array_map(static fn (string $id): array => ['id' => $id, 'severity' => 'P2', 'text' => 'Text of '.$id.'.'], array_keys($marks)),
        ]);
    }

    /**
     * @param list<string> $act
     * @param list<string> $followUps
     */
    public function judgeMarksOnly(string $round, array $act, array $followUps): void
    {
        $this->add(LedgerEventKind::JudgeDone, ['round' => $round, 'act' => $act, 'follow_ups' => $followUps]);
    }

    public function useTokens(string $round, int $prompt, int $completion): void
    {
        $call = Uuid::v7()->toRfc4122();
        $this->add(LedgerEventKind::UsageRecorded, ['call' => $call, 'phase' => 'started', 'project' => 'alpha', 'tier' => 'standard', 'round' => $round]);
        $this->add(LedgerEventKind::UsageRecorded, ['call' => $call, 'phase' => 'settled', 'usages' => [['model' => 'model-a', 'prompt' => $prompt, 'completion' => $completion, 'total' => $prompt + $completion]]]);
    }

    public function failHead(string $project, string $change, string $head, int $failures): void
    {
        $this->add(LedgerEventKind::HeadFailed, $this->headFailure($project, $change, $head, $failures));
    }

    public function handOverHead(string $project, string $change, string $head): void
    {
        $this->add(LedgerEventKind::HeadHandedOver, $this->headFailure($project, $change, $head, 4));
    }

    public function keepHeadPending(string $project, string $change, string $head): void
    {
        $this->add(LedgerEventKind::HeadPending, ['project' => $project, 'change' => $change, 'head' => $head, 'read_again_at' => '2026-10-28T12:01:00+00:00']);
    }

    public function failHeadWithoutCause(string $project, string $change): void
    {
        $this->add(LedgerEventKind::HeadFailed, ['project' => $project, 'change' => $change, 'head' => 'a', 'failures' => 1, 'retry_at' => '2026-10-28T12:01:00+00:00']);
    }

    /**
     * @return array<string, int|string>
     */
    private function headFailure(string $project, string $change, string $head, int $failures): array
    {
        return ['project' => $project, 'change' => $change, 'head' => $head, 'cause' => 'RuntimeException: commit '.$head.' not fetched', 'failures' => $failures, 'retry_at' => '2026-10-28T12:08:00+00:00'];
    }

    /**
     * @return array<string, string>
     */
    private function reviewer(string $round, string $role): array
    {
        return ['round' => $round, 'reviewer' => $round.'-'.$role, 'role' => $role, 'tier' => 'standard'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function add(LedgerEventKind $kind, array $payload): void
    {
        $this->recorded[] = new RecordedEvent(Uuid::v7(), new LedgerEvent($kind, json_encode($payload, JSON_THROW_ON_ERROR)), new DateTimeImmutable('2026-10-28T12:00:00+00:00'));
    }
}
