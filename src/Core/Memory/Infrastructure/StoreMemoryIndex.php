<?php

declare(strict_types=1);

namespace Intakt\Core\Memory\Infrastructure;

use Intakt\Core\Memory\Contract\MemoryEntry;
use Intakt\Core\Memory\Contract\MemoryIndex;
use Intakt\Core\Memory\Contract\SettledFinding;
use Override;
use Symfony\AI\Store\Indexer\DocumentIndexer;
use Symfony\AI\Store\StoreInterface;

final readonly class StoreMemoryIndex implements MemoryIndex
{
    public function __construct(
        private DocumentIndexer $indexer,
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
        // Removed first because a store may keep a second copy under the same id, and a stale copy would shadow the refreshed one.
        $this->forget($finding);
        $this->indexer->index($this->documents->toSettledDocument($finding));
    }

    #[Override]
    public function forget(SettledFinding $finding): void
    {
        $this->store->remove($this->documents->settledId($finding));
    }
}
