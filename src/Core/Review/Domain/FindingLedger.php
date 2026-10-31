<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\Finding;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// A value built per change, not a service, so the container must not wire it.
#[Exclude]
final class FindingLedger
{
    /** @var array<string, TrackedFinding> */
    private array $tracked = [];

    private int $issued = 0;

    private ?int $latestRound = null;

    /**
     * @param list<Finding> $findings
     */
    public function recordRound(int $round, array $findings): void
    {
        $this->advanceTo($round);
        $earlier = $this->tracked;
        foreach ($findings as $finding) {
            $this->track($round, $finding, $earlier);
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

    // A repeat in an earlier or the same round would land out of order in a finding's rounds or not reopen it.
    private function advanceTo(int $round): void
    {
        if (null !== $this->latestRound && $round <= $this->latestRound) {
            throw RoundNotAfterLatest::for($round, $this->latestRound);
        }

        $this->latestRound = $round;
    }

    /**
     * @param array<string, TrackedFinding> $earlier
     */
    private function track(int $round, Finding $finding, array $earlier): void
    {
        // Only ids issued before this round are repeats: a reviewer cannot know an id issued in the same round.
        if (!isset($earlier[$finding->id])) {
            $this->start($round, $finding);

            return;
        }

        $this->tracked[$finding->id] = $this->tracked[$finding->id]->seenAgain($round, $finding);
    }

    private function start(int $round, Finding $finding): void
    {
        $id = 'F'.++$this->issued;
        $this->tracked[$id] = new TrackedFinding($id, $finding, [$round], FindingStatus::Open);
    }
}
