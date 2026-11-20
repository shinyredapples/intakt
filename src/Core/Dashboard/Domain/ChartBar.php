<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// One bar of a Trends chart: the reading comes first in its tooltip and its label, the detail second (DESIGN.md, Charts); the part is the share of the bar marked inside it.
final readonly class ChartBar
{
    public function __construct(
        public string $label,
        public int $value,
        public string $reading,
        public string $detail,
        public ?int $part = null,
    ) {
    }

    public function inWords(): string
    {
        return $this->reading.', '.$this->detail;
    }
}
