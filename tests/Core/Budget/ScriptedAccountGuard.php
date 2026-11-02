<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Budget;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Budget\Contract\StartDecision;
use Intakt\Core\Budget\Contract\StartGuard;
use Override;

// Answers by account name, and records the accounts asked, so a test sees which accounts the hold looked at.
final class ScriptedAccountGuard implements StartGuard
{
    /** @var list<string> */
    public array $asked = [];

    // Keyed by Account::key(), and an account not named starts, because most tests only script the account they hold.
    /**
     * @param array<string, StartDecision> $byAccount
     */
    public function __construct(private readonly array $byAccount = [])
    {
    }

    #[Override]
    public function decide(Account $account, int $callsRunning): StartDecision
    {
        $this->asked[] = $account->key();

        return $this->byAccount[$account->key()] ?? StartDecision::Start;
    }

    // A round never merges, so no test of the hold scripts a merge.
    #[Override]
    public function decideMerge(): StartDecision
    {
        return StartDecision::Start;
    }
}
