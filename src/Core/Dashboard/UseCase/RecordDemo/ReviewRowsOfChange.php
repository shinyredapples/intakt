<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\RecordDemo;

use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageStarted;

// A row belongs to the review when it names one of its rounds, or one of the model calls made in them, because a call's settled usage names only the call.
final readonly class ReviewRowsOfChange
{
    public function __construct(
        private Ledger $ledger,
        private RoundPayloads $payloads,
    ) {
    }

    // Null when the ledger holds no round of the change in the project.
    public function of(string $project, string $change): ?ReviewRows
    {
        $rows = iterator_to_array($this->ledger->readInOrder(), false);
        $starts = $this->startsOf($rows, $project, $change);

        if ([] === $starts) {
            return null;
        }

        $rounds = array_map(static fn (ReviewedRound $start): string => $start->round, $starts);
        $calls = $this->callsIn($rows, $rounds);

        return new ReviewRows(
            $starts[0]->startedAt,
            $rounds,
            array_values(array_filter($rows, fn (RecordedEvent $row): bool => $this->belongs($row, $rounds, array_keys($calls)))),
            array_values(array_unique(array_filter($calls, static fn (?string $model): bool => null !== $model && '' !== $model))),
        );
    }

    /**
     * @param list<RecordedEvent> $rows
     *
     * @return list<ReviewedRound>
     */
    private function startsOf(array $rows, string $project, string $change): array
    {
        $starts = [];

        foreach ($rows as $row) {
            $started = LedgerEventKind::ReviewStarted === $row->event->kind ? $this->payloads->startOf($row) : null;

            if ($started instanceof ReviewedRound && $project === $started->project && $change === $started->change) {
                $starts[] = $started;
            }
        }

        return $starts;
    }

    /**
     * @param list<RecordedEvent> $rows
     * @param list<string>        $rounds
     *
     * @return array<string, string|null>
     */
    private function callsIn(array $rows, array $rounds): array
    {
        $calls = [];

        foreach ($rows as $row) {
            $started = LedgerEventKind::UsageRecorded === $row->event->kind ? $this->payloads->usageOf($row) : null;

            if ($started instanceof UsageStarted && in_array($started->round, $rounds, true)) {
                $calls[$started->call] = $started->model;
            }
        }

        return $calls;
    }

    /**
     * @param list<string>     $rounds
     * @param list<int|string> $calls
     */
    private function belongs(RecordedEvent $row, array $rounds, array $calls): bool
    {
        $payload = json_decode($row->event->payload, true);

        if (!is_array($payload)) {
            return false;
        }

        return in_array($payload['round'] ?? null, $rounds, true) || in_array($payload['call'] ?? null, $calls, true);
    }
}
