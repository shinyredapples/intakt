<?php

declare(strict_types=1);

namespace Intakt\Core\Memory\Infrastructure;

use Intakt\Core\Memory\Contract\MemoryEntry;
use Intakt\Core\Memory\Contract\MemoryIndex;
use Intakt\Core\Memory\Contract\SettledFinding;
use Override;
use Symfony\AI\Store\Document\VectorizerInterface;
use Symfony\AI\Store\Indexer\DocumentIndexer;
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
        // Removed before the add because a store may keep a second copy under the same id, and a stale copy would shadow the refreshed one.
        $this->forget($finding);
        $this->store->add($settled);
    }

    #[Override]
    public function forget(SettledFinding $finding): void
    {
        $this->store->remove($this->documents->settledId($finding));
    }
}
