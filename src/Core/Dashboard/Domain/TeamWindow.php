<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Budget\Contract\UsageWindow;
use Intakt\Core\Budget\Contract\WindowUse;

// Only the measured use and its age, because reserved and forecast have no recorded source yet and the page shows no reset time before the owner session exists.
final readonly class TeamWindow
{
    public function __construct(
        public UsageWindow $window,
        public ?WindowUse $use,
    ) {
    }

    public function label(): string
    {
        return ucfirst(str_replace('-', ' ', $this->window->value)).' window';
    }

    public function hasReading(): bool
    {
        return $this->use instanceof WindowUse;
    }

    public function used(): int
    {
        return (int) round($this->use?->usage->usedPercent ?? 0.0);
    }

    // Kept once the window has reset since the reading was taken, because that reading says what was used then, not what is used now.
    public function isKept(): bool
    {
        if (!$this->use instanceof WindowUse) {
            return false;
        }

        $resetsAt = $this->use->usage->resetsAt;
        $now = $this->use->takenAt->getTimestamp() + $this->use->ageInSeconds;

        return $this->use->ageInSeconds >= $this->window->seconds() || ($resetsAt instanceof DateTimeImmutable && $now >= $resetsAt->getTimestamp());
    }

    // No reading is said so, never shown as 0%, because an account nobody measured is not an idle one; the age shows so an old reading never reads as current.
    public function note(): string
    {
        if (!$this->use instanceof WindowUse) {
            return 'No reading yet';
        }

        $reading = $this->used().'% used, read '.Ago::inWords($this->use->ageInSeconds);

        return $this->isKept() ? 'Last known '.$reading : ucfirst($reading);
    }
}
