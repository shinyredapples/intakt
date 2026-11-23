<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Contract;

use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\PayloadFields;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;

// The one reading of main's boot lines, because the board shows its banner from it and the watcher compares a stored result with it: two readings would let the banner and the gate disagree.
final readonly class FailingMains
{
    /**
     * @return list<FailingMain>
     */
    public function in(RecordedEvents $events): array
    {
        /** @var array<string, FailingMain> $failing */
        $failing = [];
        /** @var array<string, array<string, array<string, true>>> $seen */
        $seen = [];

        foreach ($events as $recorded) {
            [$failing, $seen] = $this->settled($failing, $seen, $recorded);
        }

        return array_values($failing);
    }

    /**
     * @param array<string, FailingMain>                        $failing
     * @param array<string, array<string, array<string, true>>> $seen
     *
     * @return array{array<string, FailingMain>, array<string, array<string, array<string, true>>>}
     */
    private function settled(array $failing, array $seen, RecordedEvent $recorded): array
    {
        $payload = PayloadFields::of($recorded);
        $pair = $this->pairOf($payload);
        $reason = $this->failureReasonOf($recorded, $payload);
        $booted = LedgerEventKind::BootResultComputed === $recorded->event->kind && 'booted' === $payload->text('state');

        if (null === $pair || null === $reason && !$booted) {
            return [$failing, $seen];
        }

        [$project, $main, $tools] = $pair;

        if (null !== $reason) {
            $failing[$project] = new FailingMain($project, $main, $tools, $reason);
        } elseif ($this->readFromStore($payload) || !isset($seen[$project][$main][$tools])) {
            // Otherwise only a main commit or tool fingerprint the project has not booted or failed before clears it: a computed booted line of a pair already seen is a worker that lost the store, could not read the cache or finished late, not a recovery.
            unset($failing[$project]);
        }

        $seen[$project][$main][$tools] = true;

        return [$failing, $seen];
    }

    /**
     * @return array{string, string, string}|null
     */
    private function pairOf(PayloadFields $payload): ?array
    {
        $project = $payload->text('project');
        $main = $payload->text('main');
        $tools = $payload->text('tool_fingerprint');

        if (in_array(null, [$project, $main, $tools], true)) {
            return null;
        }

        return [$project, $main, $tools];
    }

    private function failureReasonOf(RecordedEvent $recorded, PayloadFields $payload): ?string
    {
        $reason = $payload->text('reason');
        $failedFromStore = LedgerEventKind::BootResultComputed === $recorded->event->kind && $this->readFromStore($payload) && 'failed' === $payload->text('state');

        return null !== $reason && (LedgerEventKind::MainBootFailed === $recorded->event->kind || $failedFromStore) ? $reason : null;
    }

    // A result read from the store is main's current state, because only a worker that answers the gate from the stored row writes it, and only when it differs from what this reading shows.
    private function readFromStore(PayloadFields $payload): bool
    {
        return 'store' === $payload->text('read_from');
    }
}
