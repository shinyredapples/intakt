<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Watcher\Domain\HeadAdmission;
use Intakt\Core\Watcher\Domain\HeadDecision;

final readonly class WatcherLedger
{
    public function __construct(private Ledger $ledger)
    {
    }

    // A green head is recorded by the round it starts, not here.
    public function headDecided(string $project, HeadDecision $decision): void
    {
        $kind = match ($decision->admission) {
            HeadAdmission::Pending => LedgerEventKind::HeadPending,
            HeadAdmission::Red => LedgerEventKind::HeadRed,
            HeadAdmission::Green => null,
        };

        if (null !== $kind) {
            $this->append($kind, $this->headOf($project, $decision) + ['read_again_at' => $decision->readAgainAt?->format(DATE_ATOM)]);
        }
    }

    public function roundQueued(string $project, HeadDecision $decision, string $round): void
    {
        $this->append(LedgerEventKind::RoundQueued, $this->headOf($project, $decision) + ['round' => $round]);
    }

    public function verdictsStale(string $project, string $change, string $round, string $head): void
    {
        $this->append(LedgerEventKind::VerdictsStale, ['project' => $project, 'change' => $change, 'round' => $round, 'head' => $head]);
    }

    /**
     * @return array{project: string, change: string, head: string}
     */
    private function headOf(string $project, HeadDecision $decision): array
    {
        return ['project' => $project, 'change' => $decision->change->id->value, 'head' => $decision->change->head->hash];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function append(LedgerEventKind $kind, array $payload): void
    {
        $result = $this->ledger->append(new LedgerEvent($kind, json_encode($payload, JSON_THROW_ON_ERROR)));

        if (!$result->succeeded()) {
            throw WatcherEventNotRecorded::for($kind->value, (string) $result->failure);
        }
    }
}
