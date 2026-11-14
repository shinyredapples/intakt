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
        $pair = $this->pairOf($payload);
        $reason = $payload['reason'] ?? null;
        $failed = LedgerEventKind::MainBootFailed === $recorded->event->kind && is_string($reason);
        $booted = LedgerEventKind::BootResultComputed === $recorded->event->kind && 'booted' === ($payload['state'] ?? null);

        if (null === $pair || !$failed && !$booted) {
            return [$failing, $seen];
        }

        [$project, $main, $tools] = $pair;

        if ($failed) {
            $failing[$project] = new FailingMain($project, $main, $tools, $reason);
        } elseif (!isset($seen[$project][$main][$tools])) {
            // Only a main commit or tool fingerprint the project has not booted or failed before clears it: main moves forward, so a booted line of a pair already seen is a worker that lost the store, could not read the cache or finished late, not a recovery.
            unset($failing[$project]);
        }

        $seen[$project][$main][$tools] = true;

        return [$failing, $seen];
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return array{string, string, string}|null
     */
    private function pairOf(array $payload): ?array
    {
        $project = $payload['project'] ?? null;
        $main = $payload['main'] ?? null;
        $tools = $payload['tool_fingerprint'] ?? null;

        if (!is_string($project) || !is_string($main) || !is_string($tools)) {
            return null;
        }

        return [$project, $main, $tools];
    }
}
