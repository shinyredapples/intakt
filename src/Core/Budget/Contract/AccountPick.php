<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Read back from the ledger rather than kept in memory, because the Team page runs in another process than the start that picked (design: Budget and model planner, Order of use, D6).
#[Exclude]
final readonly class AccountPick
{
    /**
     * @param list<LeftOutAccount> $leftOut
     */
    public function __construct(
        public Provider $provider,
        public ?Account $picked,
        public array $leftOut,
        public DateTimeImmutable $pickedAt,
    ) {
    }

    // Null when no left-out account names its end, because the owner must not be promised a start that no reading backs.
    public function earliestReset(): ?DateTimeImmutable
    {
        $known = array_filter(array_map(static fn (LeftOutAccount $left): ?DateTimeImmutable => $left->resetsAt, $this->leftOut));

        return [] === $known ? null : min($known);
    }
}
