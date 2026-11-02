<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Watcher\Domain\HeadAdmission;
use Intakt\Core\Watcher\Domain\HeadDecision;
use Intakt\Core\Watcher\Domain\StartedRound;

final readonly class WatcherLedger
{
    public function __construct(private Ledger $ledger)
    {
    }

    // Skipped for a green head because the round it starts is recorded instead.
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

    public function verdictsStale(StartedRound $round): void
    {
        $this->append(LedgerEventKind::VerdictsStale, ['project' => $round->project(), 'change' => $round->change(), 'round' => $round->roundId(), 'head' => $round->head()]);
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
