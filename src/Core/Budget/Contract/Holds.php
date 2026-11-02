<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use NoDiscard;

interface Holds
{
    // For a vendor's structured usage-limit answer only, never the model's own text; its reset time is untrusted input.
    #[NoDiscard]
    public function coolDown(Account $account, DateTimeImmutable $resetsAt): ReadingOutcome;

    public function hold(SharedHold $hold, DateTimeImmutable $until): void;

    public function isCoolingDown(Account $account): bool;

    // In the order of the SharedHold cases, so the first one is the one that wins.
    /**
     * @return list<SharedHold>
     */
    public function sharedInEffect(): array;
}
