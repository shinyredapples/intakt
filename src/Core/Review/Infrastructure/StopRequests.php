<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\ReviewerBlocked;
use Intakt\Core\Review\Contract\ReviewerFailed;
use Intakt\Core\Review\Contract\ReviewerStopped;
use Intakt\Core\Review\Contract\VerdictReceived;
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

    // Only while a reviewer_stopped row is a reviewer's last answer, because a stop rolled back with its lease can be followed by another run's verdict.
    /**
     * @return list<string>
     */
    public function stoppedIn(Uuid $round): array
    {
        $id = $round->toRfc4122();

        if (!array_any(iterator_to_array($this->ledger->readOfKind(LedgerEventKind::ReviewerStopped)), fn (RecordedEvent $row): bool => $id === ($this->stoppedPairOf($row)[0] ?? null))) {
            return [];
        }

        $last = [];

        foreach ($this->ledger->readInOrder() as $row) {
            $answer = $this->answerOf($row);

            if ($answer instanceof ReviewerStopped || $answer instanceof VerdictReceived || $answer instanceof ReviewerFailed || $answer instanceof ReviewerBlocked) {
                $last[$answer->round][$answer->reviewer] = $row->event->kind;
            }
        }

        return array_map(strval(...), array_keys(array_filter($last[$id] ?? [], static fn (LedgerEventKind $kind): bool => LedgerEventKind::ReviewerStopped === $kind)));
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

    private function answerOf(RecordedEvent $row): ?object
    {
        return match ($row->event->kind) {
            LedgerEventKind::VerdictReceived => $this->payloads->of($row, VerdictReceived::class),
            LedgerEventKind::ReviewerFailed => $this->payloads->of($row, ReviewerFailed::class),
            LedgerEventKind::ReviewerBlocked => $this->payloads->of($row, ReviewerBlocked::class),
            LedgerEventKind::ReviewerStopped => $this->payloads->of($row, ReviewerStopped::class),
            default => null,
        };
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
