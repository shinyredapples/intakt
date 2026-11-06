<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Domain;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'budget_item_failure')]
#[ORM\Index(name: 'budget_item_failure_error', columns: ['error_key'])]
final class ItemFailureRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    public private(set) int $position;

    public function __construct(
        #[ORM\Column(length: 255)]
        public readonly string $item,
        #[ORM\Column(length: 64)]
        public readonly string $errorKey,
        #[ORM\Column(type: 'utc_datetime_immutable')]
        public readonly DateTimeImmutable $recordedAt,
    ) {
    }
}
