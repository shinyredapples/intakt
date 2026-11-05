<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReviewChange;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\JudgeDone;
use Intakt\Core\Review\Contract\RoundTokens;
use Intakt\Core\Review\Infrastructure\LedgerPayloads;

// Reads back, because the ledger is where the judge's act ids and the vendor's token usage are recorded; usage rows stay arrays, because their payload classes belong to the vendor plugin.
final readonly class RoundLedgerReading
{
    public function __construct(
        private Ledger $ledger,
        private LedgerPayloads $payloads,
    ) {
    }

    /**
     * @return list<string>
     */
    public function actIdsOf(string $roundId): array
    {
        foreach ($this->ledger->readOfKind(LedgerEventKind::JudgeDone) as $recorded) {
            $judged = $this->payloads->of($recorded, JudgeDone::class);

            if ($roundId === $judged?->round) {
                return $judged->act;
            }
        }

        return [];
    }

    public function tokensOf(string $roundId): RoundTokens
    {
        $payloads = iterator_to_array($this->payloadsOf(LedgerEventKind::UsageRecorded), false);
        $calls = $this->callsStartedFor($roundId, $payloads);
        $ended = array_values(array_filter($payloads, static fn (array $payload): bool => in_array($payload['call'] ?? null, $calls, true) && is_array($payload['usages'] ?? null)));
        $rows = array_merge([], ...array_map($this->rowsOf(...), $ended));

        return new RoundTokens(count($calls), $this->sum($rows, 'prompt'), $this->sum($rows, 'completion'), $this->sum($rows, 'total'));
    }

    /**
     * @param list<array<array-key, mixed>> $payloads
     *
     * @return list<string>
     */
    // Only the started row names the round, so the settled or unsettled row that carries the tokens is tied to it by its call id.
    private function callsStartedFor(string $roundId, array $payloads): array
    {
        $calls = [];

        foreach ($payloads as $payload) {
            if ('started' === ($payload['phase'] ?? null) && $roundId === ($payload['round'] ?? null) && is_string($payload['call'] ?? null)) {
                $calls[] = $payload['call'];
            }
        }

        return array_values(array_unique($calls));
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
