<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\Domain;

// What the bench knows of the change on its branch, to tell a broken expectation from a miss.
final readonly class ChangeFiles
{
    /**
     * @param array<string, int> $lineCounts file path => number of lines in the file at the head
     */
    public function __construct(private array $lineCounts)
    {
    }

    public function holdsRangeOf(PlantedDefect $defect): bool
    {
        return isset($this->lineCounts[$defect->file]) && $defect->to <= $this->lineCounts[$defect->file];
    }
}
