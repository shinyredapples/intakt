<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Claimed when a commit the base does not hold lacks the orchestrator's signature, because Intakt signs every commit it pushes, so any other commit is a person's.
#[Exclude]
final readonly class MirroredHead
{
    public function __construct(
        public DateTimeImmutable $committedAt,
        public bool $claimedByPerson,
    ) {
    }
}
