<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\FailingMain;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;

// A project whose main does not boot is an infrastructure item of its own, not a pull request's, because the gate is off for every head of the project until main boots again.
final readonly class LedgerFailingMains
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
        $payload = LedgerPayload::of($recorded);
        $project = $payload['project'] ?? null;
        $reason = $payload['reason'] ?? null;
        $kind = $recorded->event->kind;

        if (!is_string($project)) {
            return [$failing, $seen];
        }

        $main = $payload['main'] ?? null;
        $tools = $payload['tool_fingerprint'] ?? null;

        if (!is_string($main) || !is_string($tools)) {
            return [$failing, $seen];
        }

        $booted = LedgerEventKind::BootResultComputed === $kind && 'booted' === ($payload['state'] ?? null);

        if (LedgerEventKind::MainBootFailed === $kind && is_string($reason)) {
            $failing[$project] = new FailingMain($project, $main, $tools, $reason);
        }

        // Only a main commit or tool fingerprint the project has not booted or failed before clears it: main moves forward, so a booted line of a pair already seen is a worker that lost the store, could not read the cache or finished late, not a recovery.
        if ($booted && !isset($seen[$project][$main][$tools])) {
            unset($failing[$project]);
        }

        if ($booted || LedgerEventKind::MainBootFailed === $kind) {
            $seen[$project][$main][$tools] = true;
        }

        return [$failing, $seen];
    }
}
