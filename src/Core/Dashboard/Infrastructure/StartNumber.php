<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

// Only the number of a review_started row, so RoundPayloads can tell a missing or null number (no number) from one that is no integer (an unreadable row).
final readonly class StartNumber
{
    public function __construct(public ?int $number = null)
    {
    }
}
