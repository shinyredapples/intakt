<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class PhpFileSelection
{
    /**
     * @param list<string> $files
     */
    private function __construct(public array $files, public bool $isSubset)
    {
    }

    /**
     * @param list<string> $files
     */
    public static function everyFile(array $files): self
    {
        return new self($files, false);
    }

    /**
     * @param list<string> $files
     */
    public static function someFiles(array $files): self
    {
        return new self($files, true);
    }
}
