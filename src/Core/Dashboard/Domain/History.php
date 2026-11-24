<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Intakt\Core\Ledger\Contract\RecordedEvents;

final readonly class History
{
    /**
     * @param list<HistoryRow> $rows
     */
    private function __construct(public array $rows)
    {
    }

    // The ledger reads oldest first and appends in order, so reversing keeps events of one second in their append order, newest last appended first.
    public static function of(RecordedEvents $events): self
    {
        $rows = [];
        $projectOfRound = [];
        foreach ($events as $recorded) {
            $row = HistoryRow::of($recorded);
            $rows[] = $row;
            if (null !== $row->round && null !== $row->project) {
                $projectOfRound[$row->round] ??= $row->project;
            }
        }

        $rows = array_map(static fn (HistoryRow $row): HistoryRow => $row->inProjectOf($projectOfRound), array_reverse($rows));
        usort($rows, static fn (HistoryRow $a, HistoryRow $b): int => $b->at <=> $a->at);

        return new self($rows);
    }
}
