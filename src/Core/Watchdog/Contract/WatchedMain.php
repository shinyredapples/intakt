<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Contract;

final readonly class WatchedMain
{
    public string $lastSeenHead;

    /**
     * @param list<RecordedMerge> $recordedMerges
     */
    public function __construct(string $lastSeenHead, public array $recordedMerges)
    {
        $this->lastSeenHead = CommitName::full($lastSeenHead, 'last seen head');
    }

    public function isRecordedHead(string $commit): bool
    {
        return array_any($this->recordedMerges, static fn (RecordedMerge $merge): bool => $merge->head === $commit);
    }
}
