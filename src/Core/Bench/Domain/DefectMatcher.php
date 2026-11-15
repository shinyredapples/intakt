<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\Domain;

use Intakt\Core\Review\Contract\Finding;

// Plain code because a model must never decide whether a finding matches, and a nearby line must not count.
final readonly class DefectMatcher
{
    public function matches(Finding $finding, PlantedDefect $defect): bool
    {
        if (null === $finding->file || null === $finding->line) {
            return false;
        }

        return $finding->file === $defect->file && $finding->line >= $defect->from && $finding->line <= $defect->to;
    }
}
