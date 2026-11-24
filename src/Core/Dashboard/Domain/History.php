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
        foreach ($events as $recorded) {
            array_unshift($rows, HistoryRow::of($recorded));
        }

        usort($rows, static fn (HistoryRow $a, HistoryRow $b): int => $b->at <=> $a->at);

        return new self($rows);
    }

    // A row that names no project belongs to every project, because it is no project's change alone.
    public function ofProject(?string $project): self
    {
        if (null === $project || '' === $project) {
            return $this;
        }

        return new self(array_values(array_filter($this->rows, static fn (HistoryRow $row): bool => null === $row->project || $project === $row->project)));
    }
}
