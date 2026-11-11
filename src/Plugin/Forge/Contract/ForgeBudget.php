<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Used and remaining are both null while no answer of this hour reported the installation's budget.
#[Exclude]
final readonly class ForgeBudget
{
    public function __construct(
        public string $installation,
        public ?int $used,
        public ?int $remaining,
        public bool $reserveInForce,
    ) {
    }

    public function isRead(): bool
    {
        return null !== $this->used && null !== $this->remaining;
    }
}
