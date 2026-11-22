<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\UseCase\RecordDemo\ReviewRows;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageStarted;

// A row belongs to the review when it names one of its rounds, or names no round and one of the model calls made in them, because a call's settled usage names only the call.
final readonly class ReplayedReviewRows
{
    public function __construct(
        private Ledger $ledger,
        private RoundPayloads $payloads,
    ) {
    }

    public function of(string $project, string $change): ?ReviewRows
    {
        $rows = iterator_to_array($this->ledger->readInOrder(), false);
        $starts = $this->startsOf($rows, static fn (ReviewedRound $start): bool => $project === $start->project && $change === $start->change);

        if ([] === $starts) {
            return null;
        }

        $rounds = array_map(static fn (ReviewedRound $start): string => $start->round, $starts);
        $calls = $this->callsIn($rows, $rounds);

        return new ReviewRows(
            $starts[0]->startedAt,
            $rounds,
            $this->rowsOf($rows, $rounds, array_keys($calls)),
            array_values(array_unique(array_filter($calls, static fn (?string $model): bool => null !== $model && '' !== $model))),
        );
    }

    // Every change's rows, because removing the demo project must leave none of its replayed reviews behind.
    /**
     * @return list<RecordedEvent>
     */
    public function ofProject(string $project): array
    {
        $rows = iterator_to_array($this->ledger->readInOrder(), false);
        $rounds = array_map(static fn (ReviewedRound $start): string => $start->round, $this->startsOf($rows, static fn (ReviewedRound $start): bool => $project === $start->project));

        return $this->rowsOf($rows, $rounds, array_keys($this->callsIn($rows, $rounds)));
    }

    /**
     * @param list<RecordedEvent>           $rows
     * @param callable(ReviewedRound): bool $wanted
     *
     * @return list<ReviewedRound>
     */
    private function startsOf(array $rows, callable $wanted): array
    {
        $starts = [];

        foreach ($rows as $row) {
            $started = LedgerEventKind::ReviewStarted === $row->event->kind ? $this->payloads->startOf($row) : null;

            if ($started instanceof ReviewedRound && $wanted($started)) {
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
     * @param list<RecordedEvent> $rows
     * @param list<string>        $rounds
     * @param list<int|string>    $calls
     *
     * @return list<RecordedEvent>
     */
    private function rowsOf(array $rows, array $rounds, array $calls): array
    {
        return array_values(array_filter($rows, fn (RecordedEvent $row): bool => $this->belongs($row, $rounds, $calls)));
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

        // A row that names a round is judged by it alone, because a call ID of the review reused under another project's round must not take that row along.
        if (array_key_exists('round', $payload)) {
            return in_array($payload['round'], $rounds, true);
        }

        return in_array($payload['call'] ?? null, $calls, true);
    }
}
