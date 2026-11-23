<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// Only the measured use, because reserved and forecast have no recorded source yet and the page shows no reset time before the owner session exists.
final readonly class TeamWindow
{
    public function __construct(
        public string $label,
        public ?float $usedPercent,
    ) {
    }

    public function used(): int
    {
        return (int) round($this->usedPercent ?? 0.0);
    }

    // No reading is said so, never shown as 0%, because an account nobody measured is not an idle one.
    public function note(): string
    {
        return null === $this->usedPercent ? 'No reading yet' : $this->used().'% used';
    }
}
