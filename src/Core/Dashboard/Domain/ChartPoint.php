<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class ChartPoint
{
    // Missing data reads "No data", never zero, because a zero would claim a count nobody recorded (DESIGN.md, Charts).
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
