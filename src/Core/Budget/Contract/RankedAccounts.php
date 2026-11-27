<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use Intakt\Core\Ai\Contract\Account;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class RankedAccounts
{
    /**
     * @param list<Account>        $inOrder the accounts a job may start on, best first
     * @param list<LeftOutAccount> $leftOut every other account with its cause
     */
    public function __construct(
        public array $inOrder,
        public array $leftOut,
    ) {
    }
}
