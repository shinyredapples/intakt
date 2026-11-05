<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Domain;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

// Hashes only, because the item and the error are compared, never shown, and the error text may hold what a log line would redact.
#[ORM\Entity]
#[ORM\Table(name: 'budget_item_failure')]
#[ORM\Index(name: 'budget_item_failure_error', columns: ['error', 'failed_at'])]
final class ItemFailureRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    public private(set) int $position;

    public function __construct(
        #[ORM\Column(length: 64)]
        public readonly string $item,
        #[ORM\Column(length: 64)]
        public readonly string $error,
        #[ORM\Column(type: 'utc_datetime_immutable')]
        public readonly DateTimeImmutable $failedAt,
    ) {
    }
}
