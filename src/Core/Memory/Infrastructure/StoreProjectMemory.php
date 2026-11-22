<?php

declare(strict_types=1);

namespace Intakt\Core\Memory\Infrastructure;

use Generator;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Memory\Contract\MemoryEntry;
use Intakt\Core\Memory\Contract\MemoryQuery;
use Intakt\Core\Memory\Contract\ProjectMemory;
use Intakt\Core\Memory\Contract\RelevantMemory;
use Intakt\Core\Memory\Contract\SettledFinding;
use Intakt\Core\Memory\Contract\SettledMemory;
use Intakt\Core\Memory\Contract\SettledQuery;
use Override;
use Symfony\AI\Store\Retriever;

final readonly class StoreProjectMemory implements ProjectMemory
{
    public function __construct(
        private Retriever $retriever,
        private MemoryDocuments $documents,
    ) {
    }

    #[Override]
    public function relevantTo(MemoryQuery $query): RelevantMemory
    {
        if ($query->files->isEmpty()) {
            return RelevantMemory::within([], $query->maxBytes);
        }

        return RelevantMemory::within($this->describing($query), $query->maxBytes);
    }

    #[Override]
    public function settledOn(SettledQuery $query): SettledMemory
    {
        $files = $query->files();

        if ($files->isEmpty()) {
            return SettledMemory::none();
        }

        return SettledMemory::sortedBy($query, $this->settledAbout($query->project, $files));
    }

    /**
     * @return Generator<SettledFinding>
     */
    private function settledAbout(string $project, Paths $files): Generator
    {
        // SettledMemory decides which findings count and whether their code is unchanged, because the store only ranks.
        foreach ($this->retriever->retrieve($this->searchText($files), ['filter' => $this->documents->settledOfProject($project)]) as $document) {
            yield $this->documents->toSettledFinding($document);
        }
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
