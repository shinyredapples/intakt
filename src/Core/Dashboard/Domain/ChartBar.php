<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// The reading leads the tooltip and the label, because DESIGN.md's charts put the value first; the part is marked inside the bar so no second color is needed.
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
