<?php

declare(strict_types=1);

namespace Intakt\Core\Memory\Infrastructure;

use Closure;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Memory\Contract\CodeSnapshot;
use Intakt\Core\Memory\Contract\MemoryEntry;
use Intakt\Core\Memory\Contract\SettledFinding;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Document\VectorDocumentInterface;

final readonly class MemoryDocuments
{
    private const string PROJECT = 'project';
    private const string FILES = 'files';
    private const string ENTRY = 'entry';
    private const string KIND = 'kind';
    private const string SETTLED = 'settled';
    private const string REASON = 'reason';
    private const string FILE = 'file';
    private const string SYMBOL = 'symbol';
    private const string CODE_HASH = 'code_hash';

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

    public function toSettledDocument(SettledFinding $finding): TextDocument
    {
        $content = $finding->code->file.' '.$finding->code->symbol.PHP_EOL.$finding->text;

        return new TextDocument($this->settledId($finding), $content, new Metadata([
            Metadata::KEY_TEXT => $content,
            self::KIND => self::SETTLED,
            self::PROJECT => $finding->project,
            self::ENTRY => $finding->text,
            self::REASON => $finding->reason,
            self::FILE => $finding->code->file,
            self::SYMBOL => $finding->code->symbol,
            self::CODE_HASH => $finding->code->hash,
        ]));
    }

    // The hash is left out of the id, so settling the same finding again after its code changed replaces the entry, and forgetting it needs no hash.
    public function settledId(SettledFinding $finding): string
    {
        return hash('sha256', json_encode([self::SETTLED, $finding->project, $finding->code->file, $finding->code->symbol, $finding->text], JSON_THROW_ON_ERROR));
    }

    // A store filter, so one install's single store never hands a project another project's notes; a document without a project is nobody's. Settled findings are a kind of their own and never count as resolved memory.
    /**
     * @return Closure(VectorDocumentInterface): bool
     */
    public function ofProject(string $project): Closure
    {
        return fn (VectorDocumentInterface $document): bool => ($document->getMetadata()[self::PROJECT] ?? null) === $project && !$this->isSettled($document);
    }

    /**
     * @return Closure(VectorDocumentInterface): bool
     */
    public function settledOfProject(string $project): Closure
    {
        return fn (VectorDocumentInterface $document): bool => ($document->getMetadata()[self::PROJECT] ?? null) === $project && $this->isSettled($document);
    }

    public function toSettledFinding(VectorDocumentInterface $document): SettledFinding
    {
        return new SettledFinding(
            $this->entryProject($document),
            $this->entryText($document),
            $this->string($document, self::REASON),
            new CodeSnapshot($this->string($document, self::FILE), $this->string($document, self::SYMBOL), $this->string($document, self::CODE_HASH)),
        );
    }

    public function toEntry(VectorDocumentInterface $document): MemoryEntry
    {
        return new MemoryEntry($this->entryProject($document), $this->entryText($document), $this->entryFiles($document));
    }

    private function isSettled(VectorDocumentInterface $document): bool
    {
        return self::SETTLED === ($document->getMetadata()[self::KIND] ?? null);
    }

    private function string(VectorDocumentInterface $document, string $key): string
    {
        $value = $document->getMetadata()[$key] ?? null;

        return is_string($value) ? $value : throw new StoredMemoryIsMalformed($document->getId(), $key);
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
