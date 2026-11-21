<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\QueuedRound;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvents;

// A queued round waits until the ledger records its start, from then on it is a review; one the watcher marked stale (a newer head replaced it) waits no more.
final readonly class LedgerQueuedRounds
{
    // Newest first, as the board orders its cards.
    /**
     * @return list<QueuedRound>
     */
    public function in(RecordedEvents $events): array
    {
        $queued = [];
        $done = [];

        foreach ($events as $recorded) {
            $kind = $recorded->event->kind;

            if (!in_array($kind, [LedgerEventKind::RoundQueued, LedgerEventKind::ReviewStarted, LedgerEventKind::VerdictsStale], true)) {
                continue;
            }

            $payload = LedgerPayload::of($recorded);
            $round = $payload['round'] ?? null;

            if (!is_string($round) || '' === $round) {
                continue;
            }

            if (LedgerEventKind::RoundQueued !== $kind) {
                $done[$round] = true;

                continue;
            }

            $queued += $this->queuedOf($round, $payload);
        }

        return array_reverse(array_values(array_diff_key($queued, $done)));
    }

    public function roundIn(RecordedEvents $events, string $round): ?QueuedRound
    {
        return array_find($this->in($events), static fn (QueuedRound $queued): bool => $queued->round === $round);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, QueuedRound>
     */
    private function queuedOf(string $round, array $payload): array
    {
        $project = $payload['project'] ?? null;
        $change = $payload['change'] ?? null;

        return is_string($project) && is_string($change) ? [$round => new QueuedRound($round, $project, $change)] : [];
    }
}
