<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use Intakt\Core\Ai\Contract\Account;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class WindowReading
{
    public function __construct(
        public Account $account,
        public UsageWindow $window,
        public Usage $usage,
    ) {
    }
}
