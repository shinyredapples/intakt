<?php

declare(strict_types=1);

namespace Intakt\Core\Memory\Infrastructure;

use Closure;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Memory\Contract\MemoryEntry;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Document\VectorDocumentInterface;

final readonly class MemoryDocuments
{
    private const string PROJECT = 'project';
    private const string FILES = 'files';
    private const string ENTRY = 'entry';

    public function toDocument(MemoryEntry $entry): TextDocument
    {
        $files = [...$entry->files];
        $content = implode(' ', $files).PHP_EOL.$entry->text;

        // The same entry gets the same id, so indexing it twice replaces it; JSON keeps file lists that join alike apart, and the project keeps two projects' equal notes apart.
        return new TextDocument(hash('sha256', json_encode([$entry->project, $files, $entry->text], JSON_THROW_ON_ERROR)), $content, new Metadata([
            Metadata::KEY_TEXT => $content,
            self::PROJECT => $entry->project,
            self::FILES => $files,
            self::ENTRY => $entry->text,
        ]));
    }

    // A store filter, so one install's single store never hands a project another project's notes; a document without a project is nobody's.
    /**
     * @return Closure(VectorDocumentInterface): bool
     */
    public function ofProject(string $project): Closure
    {
        return static fn (VectorDocumentInterface $document): bool => ($document->getMetadata()[self::PROJECT] ?? null) === $project;
    }

    public function toEntry(VectorDocumentInterface $document): MemoryEntry
    {
        return new MemoryEntry($this->entryProject($document), $this->entryText($document), $this->entryFiles($document));
    }

    private function entryProject(VectorDocumentInterface $document): string
    {
        $project = $document->getMetadata()[self::PROJECT] ?? null;

        return is_string($project) ? $project : throw new StoredMemoryIsMalformed($document->getId(), self::PROJECT);
    }

    private function entryText(VectorDocumentInterface $document): string
    {
        $text = $document->getMetadata()[self::ENTRY] ?? null;

        return is_string($text) ? $text : throw new StoredMemoryIsMalformed($document->getId(), self::ENTRY);
    }

    private function entryFiles(VectorDocumentInterface $document): Paths
    {
        $files = $document->getMetadata()[self::FILES] ?? null;

        if (!is_array($files) || !array_is_list($files)) {
            throw new StoredMemoryIsMalformed($document->getId(), self::FILES);
        }

        return new Paths(...array_map(
            fn (mixed $file): string => is_string($file) ? $file : throw new StoredMemoryIsMalformed($document->getId(), self::FILES),
            $files,
        ));
    }
}
