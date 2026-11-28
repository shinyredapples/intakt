<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Intakt\Core\Ledger\Contract\RecordedEvent;
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
        $rows = self::rowsOf($events);
        $projectOfRound = self::projectOfRound($rows);

        $rows = array_map(static fn (HistoryRow $row): HistoryRow => $row->inProjectOf($projectOfRound), array_reverse($rows));
        usort($rows, static fn (HistoryRow $a, HistoryRow $b): int => $b->at <=> $a->at);

        return new self($rows);
    }

    // The row of one event as the whole page would show it, joined to its round's project through the events that name that round.
    public static function rowOf(RecordedEvent $recorded, RecordedEvents $ofItsRound): HistoryRow
    {
        return HistoryRow::of($recorded)->inProjectOf(self::projectOfRound(self::rowsOf($ofItsRound)));
    }

    // Read after the round join, so a project known only through a reviewer's row counts too. The empty name is left out because the filter reads it as All projects.
    /**
     * @return list<string>
     */
    public function projects(): array
    {
        $projects = [];
        foreach ($this->rows as $row) {
            if (null !== $row->project && '' !== $row->project) {
                $projects[$row->project] = $row->project;
            }
        }

        return array_values($projects);
    }

    /**
     * @return list<HistoryRow>
     */
    private static function rowsOf(RecordedEvents $events): array
    {
        $rows = [];
        foreach ($events as $recorded) {
            $rows[] = HistoryRow::of($recorded);
        }

        return $rows;
    }

    // The first row in append order that names a round and a project gives the round its project.
    /**
     * @param list<HistoryRow> $rows
     *
     * @return array<string, string>
     */
    private static function projectOfRound(array $rows): array
    {
        $projectOfRound = [];
        foreach ($rows as $row) {
            if (null !== $row->round && null !== $row->project) {
                $projectOfRound[$row->round] ??= $row->project;
            }
        }

        return $projectOfRound;
    }
}
