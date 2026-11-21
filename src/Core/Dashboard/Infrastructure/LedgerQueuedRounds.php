<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\QueuedRound;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvents;

// The latest watcher line of a pull request decides, as in LedgerWaitingHeads, so a newer head or a closed pull request ends the wait; a started round is left out because from its start it is a review the board shows.
final readonly class LedgerQueuedRounds
{
    private const array WATCHER_LINES = [
        LedgerEventKind::HeadPending,
        LedgerEventKind::HeadRed,
        LedgerEventKind::HeadFailed,
        LedgerEventKind::HeadHandedOver,
        LedgerEventKind::RoundQueued,
        LedgerEventKind::HeadForgotten,
    ];

    // Newest queue line first, because Up next lists them after the board's cards, which come newest first.
    /**
     * @return list<QueuedRound>
     */
    public function in(RecordedEvents $events): array
    {
        $latest = [];
        $started = [];

        foreach ($events as $order => $recorded) {
            $kind = $recorded->event->kind;
            $payload = LedgerPayload::of($recorded);

            if (LedgerEventKind::ReviewStarted === $kind && is_string($payload['round'] ?? null)) {
                $started[$payload['round']] = true;

                continue;
            }

            $project = $payload['project'] ?? null;
            $change = $payload['change'] ?? null;

            if (!in_array($kind, self::WATCHER_LINES, true) || !is_string($project) || !is_string($change)) {
                continue;
            }

            // Keyed by project, then pull request, so no name either side sends can make two keys meet.
            $latest[$project][$change] = $this->queuedOf($kind, $project, $change, $payload['round'] ?? null, $order);
        }

        return $this->waiting($latest, $started);
    }

    /**
     * @return array{order: int, round: QueuedRound}|null
     */
    private function queuedOf(LedgerEventKind $kind, string $project, string $change, mixed $round, int $order): ?array
    {
        return LedgerEventKind::RoundQueued === $kind && is_string($round) && '' !== $round ? ['order' => $order, 'round' => new QueuedRound($round, $project, $change)] : null;
    }

    /**
     * @param array<array-key, array<array-key, array{order: int, round: QueuedRound}|null>> $latest
     * @param array<string, true>                                                            $started
     *
     * @return list<QueuedRound>
     */
    private function waiting(array $latest, array $started): array
    {
        $waiting = array_filter(array_merge([], ...array_map(static fn (array $changes): array => array_values(array_filter($changes)), array_values($latest))), static fn (array $queued): bool => !isset($started[$queued['round']->round]));
        usort($waiting, static fn (array $one, array $other): int => $other['order'] <=> $one['order']);

        return array_map(static fn (array $queued): QueuedRound => $queued['round'], $waiting);
    }
}
