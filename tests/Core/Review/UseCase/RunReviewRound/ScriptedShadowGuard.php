<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Budget\Contract\ShadowStartGuard;
use Intakt\Core\Budget\Contract\StartDecision;
use Override;

// Answers by account name, and records the accounts asked, so a test sees which accounts the hold looked at.
final class ScriptedShadowGuard implements ShadowStartGuard
{
    /** @var list<string> */
    public array $asked = [];

    // Keyed by Account::key(); an account not named starts.
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
}
