<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Used and remaining stay null while no answer of this hour reported the budget, because a figure in their place would hide that it is unknown.
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
