<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class PhpFileSelection
{
    /**
     * @param list<string> $files
     */
    private function __construct(public array $files, private int $filesLeftOut)
    {
    }

    /**
     * @param list<string> $files
     */
    public static function everyFile(array $files): self
    {
        return new self($files, 0);
    }

    /**
     * @param list<string> $files
     * @param list<string> $inScope
     */
    public static function of(array $files, array $inScope): self
    {
        return new self($files, count(array_diff($inScope, $files)));
    }

    public function isSubset(): bool
    {
        return $this->filesLeftOut > 0;
    }
}
