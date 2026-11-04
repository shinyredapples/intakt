<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Symfony\Component\Uid\Uuid;

// Read by place in the ledger, never by time, because a request and its answer can share a second, and a request made before the run began must still stop it.
final readonly class StopRequests
{
    private const array ROWS = [LedgerEventKind::ReviewerStopRequested, LedgerEventKind::ReviewerStopped];

    public function __construct(
        private Ledger $ledger,
    ) {
    }

    // Pending until a later reviewer_stopped row of the same round and reviewer answers it, so an answered request never stops a later run.
    public function pendingFor(Uuid $round, Uuid $reviewer): bool
    {
        return LedgerEventKind::ReviewerStopRequested === $this->lastRowFor($round, $reviewer);
    }

    // The newest row is the answer, so a stop of an earlier run does not count for a later one that began after it.
    public function answeredFor(Uuid $round, Uuid $reviewer): bool
    {
        return LedgerEventKind::ReviewerStopped === $this->lastRowFor($round, $reviewer);
    }

    private function lastRowFor(Uuid $round, Uuid $reviewer): ?LedgerEventKind
    {
        $pair = [$round->toRfc4122(), $reviewer->toRfc4122()];

        if (!$this->anyRequestFor($pair)) {
            return null;
        }

        $rows = array_filter(iterator_to_array($this->ledger->readInOrder()), static fn (RecordedEvent $row): bool => in_array($row->event->kind, self::ROWS, true) && self::names($row, $pair));

        return array_last($rows)?->event->kind;
    }

    // Requests are few and progress updates many, so the whole ledger is read only for a reviewer someone asked to stop.
    /**
     * @param array{string, string} $pair
     */
    private function anyRequestFor(array $pair): bool
    {
        return array_any(iterator_to_array($this->ledger->readOfKind(LedgerEventKind::ReviewerStopRequested)), static fn (RecordedEvent $row): bool => self::names($row, $pair));
    }

    /**
     * @param array{string, string} $pair
     */
    private static function names(RecordedEvent $row, array $pair): bool
    {
        $payload = json_decode($row->event->payload, true);

        return is_array($payload) && [$payload['round'] ?? null, $payload['reviewer'] ?? null] === $pair;
    }
}
