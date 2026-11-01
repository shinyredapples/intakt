<?php

declare(strict_types=1);

namespace Intakt\Core\Memory\Infrastructure;

use Generator;
use Intakt\Core\Memory\Contract\MemoryEntry;
use Intakt\Core\Memory\Contract\MemoryQuery;
use Intakt\Core\Memory\Contract\ProjectMemory;
use Intakt\Core\Memory\Contract\RelevantMemory;
use Symfony\AI\Store\Retriever;

final readonly class StoreProjectMemory implements ProjectMemory
{
    public function __construct(
        private Retriever $retriever,
        private MemoryDocuments $documents,
    ) {
    }

    public function relevantTo(MemoryQuery $query): RelevantMemory
    {
        if ([] === $query->files) {
            return RelevantMemory::within([], $query->maxBytes);
        }

        return RelevantMemory::within($this->describing($query->files), $query->maxBytes);
    }

    /**
     * @param list<string> $files
     *
     * @return Generator<MemoryEntry>
     */
    private function describing(array $files): Generator
    {
        // Decided here because the store ranks every entry by similarity and would return those about other files too.
        foreach ($this->retriever->retrieve(implode(' ', $files)) as $document) {
            $entry = $this->documents->toEntry($document);

            if ($entry->describesAnyOf($files)) {
                yield $entry;
            }
        }
    }
}
