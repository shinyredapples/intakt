<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReviewChange;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\RoundTokens;

// What a round left in the ledger: the judge's act ids and the tokens its project's calls used.
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
        $started = false;
        $calls = [];
        $prompt = $completion = $total = 0;

        foreach ($this->ledger->readInOrder() as $event) {
            $payload = $this->payloadOf($event);
            $started = $started || (LedgerEventKind::ReviewStarted === $event->event->kind && ($payload['round'] ?? null) === $roundId);

            if (!$started || LedgerEventKind::UsageRecorded !== $event->event->kind || ($payload['project'] ?? $project) !== $project || !is_array($payload['usages'] ?? null)) {
                continue;
            }

            $calls[(string) ($payload['call'] ?? '')] = true;

            foreach ($payload['usages'] as $usage) {
                $prompt += is_array($usage) ? (int) ($usage['prompt'] ?? 0) : 0;
                $completion += is_array($usage) ? (int) ($usage['completion'] ?? 0) : 0;
                $total += is_array($usage) ? (int) ($usage['total'] ?? 0) : 0;
            }
        }

        return new RoundTokens(count($calls), $prompt, $completion, $total);
    }

    /**
     * @return iterable<array<string, mixed>>
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
     * @return array<string, mixed>
     */
    private function payloadOf(RecordedEvent $event): array
    {
        $payload = json_decode($event->event->payload, true);

        return is_array($payload) ? $payload : [];
    }
}
