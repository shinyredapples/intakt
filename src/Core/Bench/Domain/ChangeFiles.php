<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\Domain;

final readonly class ChangeFiles
{
    /**
     * @param array<string, int> $lineCounts
     */
    public function __construct(private array $lineCounts)
    {
    }

    public function holdsRangeOf(PlantedDefect $defect): bool
    {
        return isset($this->lineCounts[$defect->file]) && $defect->to <= $this->lineCounts[$defect->file];
    }
}
