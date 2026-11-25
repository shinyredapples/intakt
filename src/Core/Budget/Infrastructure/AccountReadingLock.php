<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Infrastructure;

use ArrayObject;
use Closure;
use Doctrine\DBAL\Connection;
use Intakt\Core\Ai\Contract\Account;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

// One lock per account for both writers of an outage's end: recording a reading holds it across its commit and noticing an outage holds it across its last look, so neither runs between the other's update and commit on a read-committed engine.
final readonly class AccountReadingLock
{
    /**
     * @param ArrayObject<string, array{SharedLockInterface, Connection}> $heldForTransactions
     */
    public function __construct(
        private LockFactory $locks,
        private ArrayObject $heldForTransactions = new ArrayObject(),
    ) {
    }

    public function of(Account $account): SharedLockInterface
    {
        return $this->locks->createLock('budget-account-reading-'.$account->key());
    }

    // Waiting, because the other writer holds it only briefly; the caller owns its transaction's commit, so the lock stays until that transaction has ended and is given back the next time this class is used or this object goes (the lock's own expiry bounds the rest).
    public function holdUntilTheTransactionEnds(Account $account, Connection $connection): void
    {
        $this->releaseWhereTheTransactionEnded();
        if (isset($this->heldForTransactions[$account->key()])) {
            return;
        }

        $lock = $this->of($account);
        $lock->acquire(true);
        $this->heldForTransactions[$account->key()] = [$lock, $connection];
    }

    /**
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    public function around(Account $account, Closure $work): mixed
    {
        $this->releaseWhereTheTransactionEnded();
        // A reading of this caller's own open transaction holds it already, and waiting for oneself would never end.
        if (isset($this->heldForTransactions[$account->key()])) {
            return $work();
        }

        $lock = $this->of($account);
        $lock->acquire(true);

        try {
            return $work();
        } finally {
            $lock->release();
        }
    }

    private function releaseWhereTheTransactionEnded(): void
    {
        foreach ($this->heldForTransactions->getArrayCopy() as $key => [$lock, $connection]) {
            if (!$connection->isTransactionActive()) {
                $lock->release();
                unset($this->heldForTransactions[$key]);
            }
        }
    }
}
