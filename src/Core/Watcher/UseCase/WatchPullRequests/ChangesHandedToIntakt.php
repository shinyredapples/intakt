<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Plugin\Forge\Contract\OpenChange;
use JsonException;

// Decided from Intakt's own push records alone, because a pull request's author, title, labels and branch are text others can shape: docs/architecture/handed-to-intakt.md
final readonly class ChangesHandedToIntakt
{
    public function __construct(
        private Ledger $ledger,
        private WatcherLedger $records,
    ) {
    }

    /**
     * @param list<OpenChange> $changed
     *
     * @throws WatcherEventNotRecorded
     */
    public function recognizeAmong(string $project, array $changed): void
    {
        if ([] === $changed) {
            return;
        }

        $known = $this->knownAmong($project, $this->ledger->readMentioning($this->mentionsOf($changed)));

        foreach ($changed as $change) {
            $id = $change->id->value;

            if (isset($known[LedgerEventKind::ChangeOpened->value][$id]) && !isset($known[LedgerEventKind::ChangeHandedToIntakt->value][$id])) {
                $this->records->changeHandedToIntakt($project, $change);
                $known[LedgerEventKind::ChangeHandedToIntakt->value][$id] = true;
            }
        }
    }

    /**
     * @param list<OpenChange> $changed
     *
     * @return list<string>
     */
    private function mentionsOf(array $changed): array
    {
        return array_map(static fn (OpenChange $change): string => '"change":'.json_encode($change->id->value, JSON_THROW_ON_ERROR), $changed);
    }

    /**
     * @return array<string, array<string, true>>
     */
    private function knownAmong(string $project, RecordedEvents $lines): array
    {
        $known = [];

        foreach ($lines as $recorded) {
            $kind = $recorded->event->kind;

            if (LedgerEventKind::ChangeOpened !== $kind && LedgerEventKind::ChangeHandedToIntakt !== $kind) {
                continue;
            }

            $payload = $this->decoded($recorded->event->payload);
            $change = $payload['change'] ?? null;

            if ($project === ($payload['project'] ?? null) && is_string($change)) {
                $known[$kind->value][$change] = true;
            }
        }

        return $known;
    }

    /**
     * @return array<mixed>
     */
    private function decoded(string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Skipped, because a ledger row is never rewritten and one bad row must not stop a tick.
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
