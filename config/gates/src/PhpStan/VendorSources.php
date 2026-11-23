<?php

declare(strict_types=1);

namespace Intakt\Gates\PhpStan;

final readonly class VendorSources
{
    public function __construct(private string $root)
    {
    }

    // a file of the repository outside vendor/ (migrations/ too) counts as the repository's, because the repository could rename what it declares
    public function contain(string $file): bool
    {
        return AncestorDeclarations::BUILT_IN === $file || !str_starts_with($file, $this->root.'/') || str_starts_with($file, $this->root.'/vendor/');
    }
}
