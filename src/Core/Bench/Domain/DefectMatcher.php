<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\Domain;

use Intakt\Core\Review\Contract\Finding;

// Plain code on purpose: a model never decides whether a finding matches, and there is no tolerance for a nearby line.
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
