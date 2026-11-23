<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// The panel's result on the published review bench, measured on one dated run, so Trends can lead with what the reviewers catch on planted defects.
final readonly class BenchHeadline
{
    public function __construct(
        public int $found,
        public int $planted,
        public int $falseAlarms,
        public string $ranOn,
        // Null unless the bench page states it once, so the totals are never split by a guessed count.
        public ?int $runs,
    ) {
    }

    public function reading(): string
    {
        return sprintf('%d of %d', $this->found, $this->planted);
    }
}
