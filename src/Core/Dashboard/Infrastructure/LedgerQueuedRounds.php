<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\QueuedRound;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\PayloadFields;
use Intakt\Core\Ledger\Contract\RecordedEvents;

// The latest watcher line of a pull request decides, as in LedgerWaitingHeads, so a newer head or a closed pull request ends the wait; a started round is left out because from its start it is a review the board shows.
final readonly class LedgerQueuedRounds
{
    public function __construct(private RoundPayloads $payloads)
    {
    }

    // Newest queue line first, because Up next lists them after the board's cards, which come newest first.
    /**
     * @return list<QueuedRound>
     */
    public function in(RecordedEvents $events): array
    {
        $latest = [];

        foreach ($events as $order => $recorded) {
            // Only watcher lines are decoded, because the other kinds never decide a wait.
            $line = in_array($recorded->event->kind, WatcherLines::ALL, true) ? $this->watcherLineOf($recorded->event->kind, PayloadFields::of($recorded), $order) : null;

            if (null !== $line) {
                // Keyed by project, then pull request, so no name either side sends can make two keys meet.
                $latest[$line['project']][$line['change']] = $line['queued'];
            }
        }

        return $this->waiting($latest, $this->startedRounds($events));
    }

    // Read as the review page reads a start, so an unreadable start, which has no page, leaves the round queued.
    /**
     * @return array<string, true>
     */
    private function startedRounds(RecordedEvents $events): array
    {
        $started = [];

        foreach ($events as $recorded) {
            $start = LedgerEventKind::ReviewStarted === $recorded->event->kind ? $this->payloads->startOf($recorded) : null;

            if ($start instanceof ReviewedRound) {
                $started[$start->round] = true;
            }
        }

        return $started;
    }

    // A queue line without a readable round id is skipped, so it cannot erase the readable queue line before it (decision 2 of #3159's tenth round).
    /**
     * @return array{project: string, change: string, queued: array{order: int, round: QueuedRound}|null}|null
     */
    private function watcherLineOf(LedgerEventKind $kind, PayloadFields $payload, int $order): ?array
    {
        $project = $payload->text('project');
        $change = $payload->text('change');

        if (null === $project || null === $change) {
            return null;
        }

        if (LedgerEventKind::RoundQueued !== $kind) {
            return ['project' => $project, 'change' => $change, 'queued' => null];
        }

        $round = $payload->text('round') ?? '';

        return '' === $round ? null : ['project' => $project, 'change' => $change, 'queued' => ['order' => $order, 'round' => new QueuedRound($round, $project, $change)]];
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
