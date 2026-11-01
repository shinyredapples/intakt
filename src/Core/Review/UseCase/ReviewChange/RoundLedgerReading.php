<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReviewChange;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\RoundTokens;

// Reads back, because the ledger is where the judge's act ids and the vendor's token usage are recorded.
final readonly class RoundLedgerReading
{
    public function __construct(private Ledger $ledger)
    {
    }

    /**
     * @return list<string>
     */
    public function actIdsOf(string $roundId): array
    {
        foreach ($this->payloadsOf(LedgerEventKind::JudgeDone) as $payload) {
            if (($payload['round'] ?? null) === $roundId && is_array($payload['act'] ?? null)) {
                return array_values(array_filter($payload['act'], is_string(...)));
            }
        }

        return [];
    }

    // Counted from the round's start in ledger order, because a usage row names its project but not its round; another round of the project running at once is counted too.
    public function tokensSince(string $roundId, string $project): RoundTokens
    {
        $payloads = array_values(array_filter($this->payloadsAfterStartOf($roundId), static fn (array $payload): bool => ($payload['project'] ?? null) === $project && is_array($payload['usages'] ?? null)));
        $rows = array_merge([], ...array_map($this->rowsOf(...), $payloads));
        $calls = array_unique(array_map(static fn (array $payload): string => is_string($payload['call'] ?? null) ? $payload['call'] : '', $payloads));

        return new RoundTokens(count($calls), $this->sum($rows, 'prompt'), $this->sum($rows, 'completion'), $this->sum($rows, 'total'));
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return list<array<array-key, mixed>>
     */
    private function rowsOf(array $payload): array
    {
        $usages = $payload['usages'] ?? [];

        return is_array($usages) ? array_values(array_filter($usages, is_array(...))) : [];
    }

    /**
     * @param list<array<array-key, mixed>> $rows
     */
    private function sum(array $rows, string $key): int
    {
        return array_sum(array_map(static fn (array $row): int => is_int($row[$key] ?? null) ? $row[$key] : 0, $rows));
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function payloadsAfterStartOf(string $roundId): array
    {
        $after = [];
        $started = false;

        foreach ($this->ledger->readInOrder() as $event) {
            $payload = $this->payloadOf($event);
            $started = $started || (LedgerEventKind::ReviewStarted === $event->event->kind && ($payload['round'] ?? null) === $roundId);

            if ($started && LedgerEventKind::UsageRecorded === $event->event->kind) {
                $after[] = $payload;
            }
        }

        return $after;
    }

    /**
     * @return iterable<array<array-key, mixed>>
     */
    private function payloadsOf(LedgerEventKind $kind): iterable
    {
        foreach ($this->ledger->readInOrder() as $event) {
            if ($kind === $event->event->kind) {
                yield $this->payloadOf($event);
            }
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private function payloadOf(RecordedEvent $event): array
    {
        $payload = json_decode($event->event->payload, true);

        return is_array($payload) ? $payload : [];
    }
}
