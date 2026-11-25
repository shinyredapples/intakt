<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\DocsPages;

// The Technical writer reads only the pages people read, so plain code cuts every other file out of the diff before its prompt is built (docs/design/05-architecture/14-threat-model.md#the-technical-writers-session).
final readonly class DocsPartOfDiff
{
    // Only a header starts a file, because every line inside a hunk starts with a space, "+", "-" or "\".
    private const string FILE_HEADER = 'diff --git ';

    public function __construct(
        private DiffPaths $paths = new DiffPaths(),
        private DocsPages $docs = new DocsPages(),
    ) {
    }

    public function of(string $diff): string
    {
        $files = preg_split('/^(?='.preg_quote(self::FILE_HEADER, '/').')/m', $diff);

        return implode('', array_filter(false === $files ? [] : $files, $this->isDocsPage(...)));
    }

    // Kept when any path it names is a page, so a page renamed to or from another file stays in view.
    private function isDocsPage(string $file): bool
    {
        return str_starts_with($file, self::FILE_HEADER) && array_any($this->paths->in($file), $this->docs->contains(...));
    }
}
