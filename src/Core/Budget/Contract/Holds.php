<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Account;
use NoDiscard;

interface Holds
{
    // Only for a vendor's structured usage-limit answer, because the model's own text can be steered; the reset time is checked since it is untrusted input.
    #[NoDiscard]
    public function coolDown(Account $account, DateTimeImmutable $resetsAt): ReadingOutcome;

    public function hold(SharedHold $hold, DateTimeImmutable $until): void;

    public function isCoolingDown(Account $account): bool;

    // The end of the latest cooldown of the account still ahead, null when none is, so a caller waits for the hold that lasts longest.
    public function coolingDownUntil(Account $account): ?DateTimeImmutable;

    // In the order of the SharedHold cases, so the first one is the one that wins.
    /**
     * @return list<SharedHold>
     */
    public function sharedInEffect(): array;
}
