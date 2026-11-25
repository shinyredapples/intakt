<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class DocsLimits
{
    public const int PARAGRAPH_WORDS = 80;

    // Because AGENTS.md asks for pages a person reads in one sitting, and about 1,500 words is where it already asks a technical page for a summary.
    public const int PAGE_WORDS = 1500;

    private const array LIMITED_FOLDERS = ['docs/user/', 'docs/guide/'];

    public function limitsPage(string $path): bool
    {
        return array_any(self::LIMITED_FOLDERS, static fn (string $folder): bool => str_starts_with($path, $folder));
    }

    public function limitedFolders(): string
    {
        return implode(' and ', self::LIMITED_FOLDERS);
    }
}
