<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class ChartPoint
{
    // DESIGN.md, Charts: missing data reads "No data" in the tooltip and the table, never zero.
    public const string NO_DATA = 'No data';

    /**
     * @param int<0, max>|null $value
     */
    public function __construct(
        public string $label,
        public ?int $value,
    ) {
    }

    public function reading(): string
    {
        return null === $this->value ? self::NO_DATA : (string) $this->value;
    }
}
