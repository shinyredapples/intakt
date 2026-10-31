<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\Finding;

final class FindingLedger
{
    /** @var array<string, TrackedFinding> */
    private array $tracked = [];

    private int $issued = 0;

    /**
     * @param list<Finding> $findings
     */
    public function recordRound(int $round, array $findings): void
    {
        $seen = [];
        foreach ($findings as $finding) {
            $tracked = $this->track($round, $finding, $seen);
            $seen[$tracked->id] = true;
        }
    }

    /**
     * @param list<string> $ids
     */
    public function resolve(array $ids): void
    {
        foreach ($ids as $id) {
            if (isset($this->tracked[$id])) {
                $this->tracked[$id] = $this->tracked[$id]->resolved();
            }
        }
    }

    /**
     * @return list<TrackedFinding>
     */
    public function open(): array
    {
        return array_values(array_filter($this->tracked, static fn (TrackedFinding $tracked): bool => $tracked->isOpen()));
    }

    /**
     * @return list<TrackedFinding>
     */
    public function all(): array
    {
        return array_values($this->tracked);
    }

    /**
     * @param array<string, true> $seen
     */
    private function track(int $round, Finding $finding, array $seen): TrackedFinding
    {
        $known = $this->tracked[$finding->id] ?? null;
        if (null === $known || isset($seen[$known->id])) {
            return $this->start($round, $finding);
        }

        return $this->tracked[$known->id] = $known->seenAgain($round, $finding);
    }

    private function start(int $round, Finding $finding): TrackedFinding
    {
        $id = 'F'.++$this->issued;

        return $this->tracked[$id] = new TrackedFinding($id, $finding, [$round], FindingStatus::Open);
    }
}
