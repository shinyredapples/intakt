<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The token of one account, for a CLI that signs in outside the app's own process; the account goes with it, so the usage is recorded for the account that spent it.
#[Exclude]
final readonly class AccountLogin
{
    public function __construct(
        public Account $account,
        #[SensitiveParameter]
        public string $token,
    ) {
    }
}
