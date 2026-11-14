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

        foreach ($events as $recorded) {
            $failing = $this->settled($failing, $recorded);
        }

        return array_values($failing);
    }

    /**
     * @param array<string, FailingMain> $failing
     *
     * @return array<string, FailingMain>
     */
    private function settled(array $failing, RecordedEvent $recorded): array
    {
        $payload = LedgerPayload::of($recorded);
        $project = $payload['project'] ?? null;
        $reason = $payload['reason'] ?? null;
        $kind = $recorded->event->kind;

        if (!is_string($project)) {
            return $failing;
        }

        if (LedgerEventKind::MainBootFailed === $kind && is_string($reason)) {
            $failing[$project] = new FailingMain($project, $reason);
        }

        if (LedgerEventKind::BootResultComputed === $kind && 'booted' === ($payload['state'] ?? null)) {
            unset($failing[$project]);
        }

        return $failing;
    }
}
