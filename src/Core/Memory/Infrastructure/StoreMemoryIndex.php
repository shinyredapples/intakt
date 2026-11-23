<?php

declare(strict_types=1);

namespace Intakt\Core\Memory\Infrastructure;

use Intakt\Core\Memory\Contract\MemoryEntry;
use Intakt\Core\Memory\Contract\MemoryIndex;
use Intakt\Core\Memory\Contract\SettledFinding;
use Override;
use Symfony\AI\Store\Document\VectorizerInterface;
use Symfony\AI\Store\Indexer\DocumentIndexer;
use Symfony\AI\Store\InMemory\Store;
use Symfony\AI\Store\StoreInterface;

final readonly class StoreMemoryIndex implements MemoryIndex
{
    public function __construct(
        private DocumentIndexer $indexer,
        private VectorizerInterface $vectorizer,
        private StoreInterface $store,
        private MemoryDocuments $documents,
    ) {
    }

    #[Override]
    public function index(MemoryEntry $entry): void
    {
        $this->indexer->index($this->documents->toDocument($entry));
    }

    #[Override]
    public function settle(SettledFinding $finding): void
    {
        // Embedded before anything is removed, so a failing embedder leaves the earlier entry in place.
        $settled = $this->vectorizer->vectorize($this->documents->toSettledDocument($finding));
        // Only the in-memory store appends a second copy under the same id; the SQLite store replaces the row inside its own transaction, so a separate delete there could commit before a failing insert and lose the entry.
        if ($this->store instanceof Store) {
            $this->forget($finding);
        }

        $this->store->add($settled);
    }

    #[Override]
    public function forget(SettledFinding $finding): void
    {
        $this->store->remove($this->documents->settledId($finding));
    }
}
