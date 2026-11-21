<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use DateTimeImmutable;

interface AccountCooldowns
{
    // One minute because a provider that sends no reset still gets a pause, and it is the async transport's longest wait between two tries.
    public const int SECONDS_WITHOUT_RESET = 60;

    // At most a day, so a huge reset never puts an account or a pinned provider out of reach and the window stays inside its one-month period.
    public const int LONGEST_SECONDS = 86400;

    public function coolDown(Account $account, ?int $resetInSeconds): void;

    // Apart from coolDown(), because only a subscription plan's limit without a reset waits for the plan's measured reset (issue #1749); an outage keeps its short pause.
    public function coolDownUntilPlanResets(Account $account): void;

    // A rejected key is remembered with its cooldown, because a wait for an account that rejected its key brings nothing back; it is reported to the owner once, because only the owner can fix it.
    public function coolDownRejected(Account $account): void;

    // Takes the moment the call was sent, because only an answer to a call sent after an account error was reported shows the error is gone.
    public function answered(Account $account, DateTimeImmutable $sentAt): void;

    public function isCoolingDown(Account $account): bool;

    public function isRejected(Account $account): bool;

    // False for a provider without accounts, because then nothing was rejected.
    public function isEveryAccountRejected(Provider $provider): bool;

    // Zero while the account is free, else at least a second, so a cooling account is never reported free and retried at once.
    public function secondsUntilFree(Account $account): int;

    // Zero while an account of the provider is free, so a reviewer moves to it at once.
    public function secondsUntilAnAccountIsFree(Provider $provider): int;
}
