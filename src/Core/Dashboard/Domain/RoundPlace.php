<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// Empty change and no number for a round started before the ledger named them, because older starts carry neither; such a round is then a review of its own.
final readonly class RoundPlace
{
    public function __construct(
        public string $change,
        public ?int $number,
    ) {
    }
}
