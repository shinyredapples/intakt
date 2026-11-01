<?php

declare(strict_types=1);

namespace Intakt\Core\Memory\Infrastructure;

use Intakt\Core\Memory\Contract\MemoryEntry;
use Intakt\Core\Memory\Contract\MemoryIndex;
use Symfony\AI\Store\Indexer\DocumentIndexer;

final readonly class StoreMemoryIndex implements MemoryIndex
{
    public function __construct(
        private DocumentIndexer $indexer,
        private MemoryDocuments $documents,
    ) {
    }

    public function index(MemoryEntry $entry): void
    {
        $this->indexer->index($this->documents->toDocument($entry));
    }
}
