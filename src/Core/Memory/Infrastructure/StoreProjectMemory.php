<?php

declare(strict_types=1);

namespace Intakt\Core\Memory\Infrastructure;

use Generator;
use Intakt\Core\Git\Contract\Paths;
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
        if ($query->files->isEmpty()) {
            return RelevantMemory::within([], $query->maxBytes);
        }

        return RelevantMemory::within($this->describing($query), $query->maxBytes);
    }

    /**
     * @return Generator<MemoryEntry>
     */
    private function describing(MemoryQuery $query): Generator
    {
        $documents = $this->retriever->retrieve($this->searchText($query->files), ['filter' => $this->documents->ofProject($query->project)]);

        // Decided here because the store ranks every entry by similarity and would return those about other files too.
        foreach ($documents as $document) {
            $entry = $this->documents->toEntry($document);

            if ($entry->describesAnyOf($query->files)) {
                yield $entry;
            }
        }
    }

    private function searchText(Paths $files): string
    {
        // The SQLite store quotes FTS5 terms without escaping a quote (upstream/ai-sqlite-store-unescaped-fts-quote), and its tokenizer drops quotes anyway.
        return str_replace('"', ' ', implode(' ', [...$files]));
    }
}
