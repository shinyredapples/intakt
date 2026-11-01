<?php

declare(strict_types=1);

namespace Intakt\Core\Memory\Infrastructure;

use Intakt\Core\Memory\Contract\MemoryEntry;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Document\VectorDocumentInterface;

final readonly class MemoryDocuments
{
    private const string FILES = 'files';
    private const string ENTRY = 'entry';

    public function toDocument(MemoryEntry $entry): TextDocument
    {
        $content = implode(' ', $entry->files).PHP_EOL.$entry->text;

        // The same entry gets the same id, so indexing it twice replaces it.
        return new TextDocument(hash('sha256', $content), $content, new Metadata([
            Metadata::KEY_TEXT => $content,
            self::FILES => $entry->files,
            self::ENTRY => $entry->text,
        ]));
    }

    public function toEntry(VectorDocumentInterface $document): MemoryEntry
    {
        $metadata = $document->getMetadata();

        /** @var list<string> $files */
        $files = $metadata[self::FILES];

        return new MemoryEntry((string) $metadata[self::ENTRY], $files);
    }
}
