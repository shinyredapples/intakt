<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

final readonly class RepositoryCode
{
    private const array FOLDERS = ['/src/', '/tests/', '/bin/', '/config/gates/src/'];

    public function __construct(private string $root)
    {
    }

    public function holds(string $file): bool
    {
        return array_any(self::FOLDERS, fn (string $folder): bool => str_starts_with($file, $this->root.$folder));
    }
}
