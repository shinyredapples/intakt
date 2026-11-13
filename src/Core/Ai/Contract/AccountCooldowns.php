<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use DateTimeImmutable;

interface AccountCooldowns
{
    public function coolDown(Account $account, ?int $resetInSeconds): void;

    // A rejected key is remembered with its cooldown, because a wait for an account that rejected its key brings nothing back; it is reported to the owner once, because only the owner can fix it.
    public function coolDownRejected(Account $account): void;

    // Takes the moment the call was sent, because only an answer to a call sent after an account error was reported shows the error is gone.
    public function answered(Account $account, DateTimeImmutable $sentAt): void;

    public function isCoolingDown(Account $account): bool;

    public function isRejected(Account $account): bool;

    // False for a provider without accounts, because then nothing was rejected.
    public function isEveryAccountRejected(Provider $provider): bool;

    // Zero while an account of the provider is free, so a reviewer moves to it at once.
    public function secondsUntilAnAccountIsFree(Provider $provider): int;
}
