<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Domain;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Intakt\Core\Budget\Contract\SharedHold;

#[ORM\Entity]
#[ORM\Table(name: 'budget_shared_hold')]
final class SharedHoldRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column]
    public private(set) int $position;

    public function __construct(
        #[ORM\Column(length: 16, enumType: SharedHold::class)]
        public readonly SharedHold $hold,
        #[ORM\Column(type: 'utc_datetime_immutable')]
        public readonly DateTimeImmutable $until,
        #[ORM\Column(type: 'utc_datetime_immutable')]
        public readonly DateTimeImmutable $placedAt,
    ) {
    }
}
