<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\ReviewerStopped;
use Symfony\Component\Uid\Uuid;

// Read by place in the ledger, never by time, because a request and its answer can share a second, and a request made before the run began must still stop it.
final readonly class StopRequests
{
    private const array ROWS = [LedgerEventKind::ReviewerStopRequested, LedgerEventKind::ReviewerStopped];

    public function __construct(
        private Ledger $ledger,
        private LedgerPayloads $payloads,
    ) {
    }

    // Pending until a later reviewer_stopped row of the same round and reviewer answers it, so an answered request never stops a later run.
    public function pendingFor(Uuid $round, Uuid $reviewer): bool
    {
        return LedgerEventKind::ReviewerStopRequested === $this->lastRowFor($round, $reviewer);
    }

    private function lastRowFor(Uuid $round, Uuid $reviewer): ?LedgerEventKind
    {
        $pair = [$round->toRfc4122(), $reviewer->toRfc4122()];

        if (!$this->anyRequestFor($pair)) {
            return null;
        }

        $rows = array_filter(iterator_to_array($this->ledger->readInOrder()), fn (RecordedEvent $row): bool => in_array($row->event->kind, self::ROWS, true) && $pair === $this->pairOf($row));

        return array_last($rows)?->event->kind;
    }

    // Requests are few and progress updates many, so the whole ledger is read only for a reviewer someone asked to stop.
    /**
     * @param array{string, string} $pair
     */
    private function anyRequestFor(array $pair): bool
    {
        return array_any(iterator_to_array($this->ledger->readOfKind(LedgerEventKind::ReviewerStopRequested)), fn (RecordedEvent $row): bool => $pair === $this->pairOf($row));
    }

    /**
     * @return array{mixed, mixed}|null
     */
    private function pairOf(RecordedEvent $row): ?array
    {
        return LedgerEventKind::ReviewerStopped === $row->event->kind ? $this->stoppedPairOf($row) : $this->requestedPairOf($row);
    }

    /**
     * @return array{string, string}|null
     */
    private function stoppedPairOf(RecordedEvent $row): ?array
    {
        $stopped = $this->payloads->of($row, ReviewerStopped::class);

        return null === $stopped ? null : [$stopped->round, $stopped->reviewer];
    }

    // Read as an array, because the dashboard writes the request row without a payload class.
    /**
     * @return array{mixed, mixed}|null
     */
    private function requestedPairOf(RecordedEvent $row): ?array
    {
        $payload = json_decode($row->event->payload, true);

        return is_array($payload) ? [$payload['round'] ?? null, $payload['reviewer'] ?? null] : null;
    }
}
