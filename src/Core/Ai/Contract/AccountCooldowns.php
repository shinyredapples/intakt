<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use DateTimeImmutable;

interface AccountCooldowns
{
    public function coolDown(Account $account, ?int $resetInSeconds): void;

    // A rejected key is remembered with its cooldown, because a wait for an account that rejected its key brings nothing back.
    public function coolDownRejected(Account $account): void;

    // Reported to the owner once per account and error, because each later call fails the same way until the owner acts.
    public function coolDownUnusable(Account $account, AccountError $error): void;

    // Only an answer to a call sent after a report rearms it, because an older call can answer after the error and proves nothing about the account since.
    public function answered(Account $account, DateTimeImmutable $sentAt): void;

    public function isCoolingDown(Account $account): bool;

    public function isRejected(Account $account): bool;

    // False for a provider without accounts, because then nothing was rejected.
    public function isEveryAccountRejected(Provider $provider): bool;

    // Zero while an account of the provider is free, so a reviewer moves to it at once.
    public function secondsUntilAnAccountIsFree(Provider $provider): int;
}
