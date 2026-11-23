<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// The reading leads the tooltip and the label, because DESIGN.md's charts put the value first; the part is marked inside the bar so no second color is needed. A null value draws no bar and reads "No data", never zero; shown is the direct label when the value is a scaled figure such as a cost.
final readonly class ChartBar
{
    public function __construct(
        public string $label,
        public ?int $value,
        public string $reading,
        public string $detail,
        public ?int $part = null,
        public ?string $shown = null,
    ) {
    }

    public function inWords(): string
    {
        return $this->reading.', '.$this->detail;
    }

    public function directLabel(): string
    {
        return $this->shown ?? (string) $this->value;
    }
}
