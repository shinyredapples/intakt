<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use Intakt\Core\Ai\Contract\Account;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class RankedAccounts
{
    // The usable accounts best first, and every other account with its cause, so a caller can say why one was not picked.
    /**
     * @param list<Account>        $inOrder
     * @param list<LeftOutAccount> $leftOut
     */
    public function __construct(
        public array $inOrder,
        public array $leftOut,
    ) {
    }
}
