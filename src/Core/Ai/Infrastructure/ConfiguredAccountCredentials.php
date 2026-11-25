<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCredentials;
use Intakt\Core\Ai\Contract\EnabledAccounts;
use Intakt\Core\Ai\Contract\Provider;
use Override;

final readonly class ConfiguredAccountCredentials implements AccountCredentials
{
    public function __construct(
        private EnabledAccounts $enabled,
        private CodexLogins $codexLogins,
    ) {
    }

    // Codex has no variables, so its enabled accounts are every listed one; its credential is the kept login file instead.
    #[Override]
    public function areSet(Account $account): bool
    {
        if (!in_array($account->key(), array_map(static fn (Account $enabled): string => $enabled->key(), $this->enabled->of($account->provider)), true)) {
            return false;
        }

        return Provider::Codex !== $account->provider || $this->codexLogins->hasLogin($account->name);
    }
}
