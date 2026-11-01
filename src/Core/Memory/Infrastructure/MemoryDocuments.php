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

        // The same entry gets the same id, so indexing it twice replaces it; JSON keeps file lists that join alike apart.
        return new TextDocument(hash('sha256', json_encode([$entry->files, $entry->text], JSON_THROW_ON_ERROR)), $content, new Metadata([
            Metadata::KEY_TEXT => $content,
            self::FILES => $entry->files,
            self::ENTRY => $entry->text,
        ]));
    }

    public function toEntry(VectorDocumentInterface $document): MemoryEntry
    {
        return new MemoryEntry($this->entryText($document), $this->entryFiles($document));
    }

    private function entryText(VectorDocumentInterface $document): string
    {
        $text = $document->getMetadata()[self::ENTRY] ?? null;

        return is_string($text) ? $text : throw new StoredMemoryIsMalformed($document->getId(), self::ENTRY);
    }

    /**
     * @return list<string>
     */
    private function entryFiles(VectorDocumentInterface $document): array
    {
        $files = $document->getMetadata()[self::FILES] ?? null;

        if (!is_array($files) || !array_is_list($files) || !array_all($files, static fn (mixed $file): bool => is_string($file))) {
            throw new StoredMemoryIsMalformed($document->getId(), self::FILES);
        }

        return $files;
    }
}
