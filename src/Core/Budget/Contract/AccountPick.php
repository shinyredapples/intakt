<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// One start's pick as the ledger holds it: the account the call went to first, or none, and every account left out with its cause.
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
