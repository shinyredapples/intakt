<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use DateTimeImmutable;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Watcher\Domain\HeadAdmission;
use Intakt\Core\Watcher\Domain\HeadDecision;
use Intakt\Core\Watcher\Domain\HeadFailure;
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

    public function roundQueued(StartedRound $round): void
    {
        $this->append(LedgerEventKind::RoundQueued, ['project' => $round->project(), 'change' => $round->change(), 'head' => $round->head(), 'round' => $round->roundId()]);
    }

    public function verdictsStale(StartedRound $round): void
    {
        $this->append(LedgerEventKind::VerdictsStale, ['project' => $round->project(), 'change' => $round->change(), 'round' => $round->roundId(), 'head' => $round->head()]);
    }

    public function headFailed(HeadFailure $failure, int $failures, DateTimeImmutable $retryAt): void
    {
        $this->append(LedgerEventKind::HeadFailed, $this->failureOf($failure, $failures) + ['retry_at' => $retryAt->format(DATE_ATOM)]);
    }

    public function headHandedOver(HeadFailure $failure, int $failures): void
    {
        $this->append(LedgerEventKind::HeadHandedOver, $this->failureOf($failure, $failures));
    }

    /**
     * @return array{project: string, change: string, head: string, cause: string, failures: int}
     */
    private function failureOf(HeadFailure $failure, int $failures): array
    {
        return ['project' => $failure->project, 'change' => $failure->head->id->value, 'head' => $failure->head->head->hash, 'cause' => $failure->cause, 'failures' => $failures];
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
