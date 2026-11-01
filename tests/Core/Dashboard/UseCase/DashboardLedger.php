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
        $this->recorded[] = new RecordedEvent(Uuid::v7(), $event, new DateTimeImmutable('2026-10-28 12:00:00 UTC'));

        return AppendResult::appended(Uuid::v7());
    }

    public function readInOrder(): RecordedEvents
    {
        return new RecordedEvents($this->recorded);
    }

    public function startRound(string $round, string $project): void
    {
        $this->add(LedgerEventKind::ReviewStarted, ['round' => $round, 'project' => $project, 'reviewers' => 2]);
    }

    public function judgeRound(string $round): void
    {
        $this->add(LedgerEventKind::JudgeDone, ['round' => $round, 'act' => [], 'follow_ups' => []]);
    }

    /**
     * @param array<string, int|string|list<string>> $payload
     */
    private function add(LedgerEventKind $kind, array $payload): void
    {
        $this->recorded[] = new RecordedEvent(Uuid::v7(), new LedgerEvent($kind, json_encode($payload, JSON_THROW_ON_ERROR)), new DateTimeImmutable('2026-10-28 12:00:00 UTC'));
    }
}
