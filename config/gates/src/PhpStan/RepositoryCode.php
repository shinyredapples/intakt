<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

// one list for every rule that judges only the repository's own code, so their coverage cannot drift apart
final readonly class RepositoryCode
{
    private const array FOLDERS = ['/src/', '/tests/', '/bin/', '/config/gates/src/'];

    public function __construct(private string $root)
    {
    }

    public function contains(string $file): bool
    {
        return array_any(self::FOLDERS, fn (string $folder): bool => str_starts_with($file, $this->root.$folder));
    }
}
