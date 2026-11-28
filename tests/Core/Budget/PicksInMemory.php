<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\AccountPick;
use Intakt\Core\Budget\Contract\AccountPicks;
use Intakt\Core\Budget\Contract\RankedAccounts;
use Override;

// Keeps each recorded pick in order, so a test can read what a start recorded without a ledger.
final class PicksInMemory implements AccountPicks
{
    /** @var list<AccountPick> */
    private array $picks = [];

    #[Override]
    public function record(Provider $provider, RankedAccounts $pick): void
    {
        $this->picks[] = new AccountPick($provider, array_first($pick->inOrder), $pick->leftOut, new DateTimeImmutable('2026-11-28T12:00:00Z'));
    }

    #[Override]
    public function latestOf(Provider $provider): ?AccountPick
    {
        return array_last(array_values(array_filter($this->picks, static fn (AccountPick $pick): bool => $provider === $pick->provider)));
    }

    /**
     * @return list<AccountPick>
     */
    public function all(): array
    {
        return $this->picks;
    }
}
