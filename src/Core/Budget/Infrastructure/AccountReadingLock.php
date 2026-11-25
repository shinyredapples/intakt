<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Infrastructure;

use Closure;
use Intakt\Core\Ai\Contract\Account;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

// One lock per account for both writers of an outage's end: recording a reading holds it across its commit and noticing an outage holds it across its last look, so neither runs between the other's update and commit on a read-committed engine.
final readonly class AccountReadingLock
{
    public function __construct(
        private LockFactory $locks,
    ) {
    }

    public function of(Account $account): SharedLockInterface
    {
        return $this->locks->createLock('budget-account-reading-'.$account->key());
    }

    // Waiting, because the other writer holds it only briefly; always given back, so no state outlives the call.
    /**
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    public function around(Account $account, Closure $work): mixed
    {
        $lock = $this->of($account);
        $lock->acquire(true);

        try {
            return $work();
        } finally {
            $lock->release();
        }
    }
}
