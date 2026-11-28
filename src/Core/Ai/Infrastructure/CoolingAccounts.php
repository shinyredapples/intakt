<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Budget\Contract\AccountRanking;
use Intakt\Core\Budget\Contract\LeftOutAccount;
use Intakt\Core\Budget\Contract\LeftOutCause;
use Intakt\Core\Budget\Contract\RankedAccounts;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The accounts of one provider that a call leaves out for a cooldown, apart from AccountFailoverPlatform because the ranking never sees them: startable() drops them before it ranks. Excluded from the container because AccountFailoverPlatform builds one per provider.
#[Exclude]
final readonly class CoolingAccounts
{
    public function __construct(
        private Provider $provider,
        private AccountRanking $ranking,
        private ClockInterface $clock,
    ) {
    }

    // The pick also names each account this call drops for a cooldown, because the ranking saw only the rest and the owner must see why every account was not used.
    /**
     * @param array<string, int> $waits
     */
    public function pickOf(RankedAccounts $ranked, array $waits): RankedAccounts
    {
        $now = $this->clock->now();
        $held = [];
        foreach ($this->leftOut($waits) as $left) {
            $held[$left->account->name] = $left;
        }

        $cooling = [];
        foreach ($waits as $name => $wait) {
            if (0 < $wait) {
                $cooling[] = $this->holdWhileCooling(new Account($this->provider, $name), $now->modify(sprintf('+%d seconds', $wait)), $held[$name] ?? null);
            }
        }

        return new RankedAccounts($ranked->inOrder, [...$ranked->leftOut, ...$cooling]);
    }

    // The cooling accounts are ranked for the wait only, never for the send order, so a ceiling that outlasts a cooldown still reaches the wait.
    /**
     * @param array<string, int> $waits
     *
     * @return list<LeftOutAccount>
     */
    public function leftOut(array $waits): array
    {
        $cooling = [];
        foreach ($waits as $name => $wait) {
            if (0 < $wait) {
                $cooling[] = new Account($this->provider, $name);
            }
        }

        return [] === $cooling ? [] : $this->ranking->rank($cooling)->leftOut;
    }

    // The hold that ends last names the cause, an unknown end counting as last, because the account starts again only once both have ended and the owner must see the one that sets the reset.
    private function holdWhileCooling(Account $account, DateTimeImmutable $cooledUntil, ?LeftOutAccount $held): LeftOutAccount
    {
        if ($held instanceof LeftOutAccount && (!$held->resetsAt instanceof DateTimeImmutable || $held->resetsAt > $cooledUntil)) {
            return $held;
        }

        return new LeftOutAccount($account, LeftOutCause::CoolingDown, $cooledUntil);
    }
}
