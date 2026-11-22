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

    /**
     * @throws CommitNameNotFull
     */
    public function mustNameFullCommitsOfLength(int $length): void
    {
        CommitName::fullOfLength($this->lastSeenHead, 'last seen head', $length);
        foreach ($this->recordedMerges as $merge) {
            CommitName::fullOfLength($merge->base, 'recorded merge base', $length);
            CommitName::fullOfLength($merge->head, 'recorded merge head', $length);
        }
    }

    public function isRecordedHead(string $commit): bool
    {
        return array_any($this->recordedMerges, static fn (RecordedMerge $merge): bool => $merge->head === $commit);
    }
}
