<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\AccountLogin;
use Intakt\Core\Ai\Contract\AccountLogins;
use Intakt\Core\Ai\Contract\AiServices;
use Intakt\Core\Ai\Contract\EveryAccountCoolingDown;
use Intakt\Core\Ai\Contract\EveryKeyRejected;
use Intakt\Core\Ai\Contract\Provider;
use Override;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class EnvironmentAccountLogins implements AccountLogins
{
    /**
     * @param array<string, string> $tokens
     */
    public function __construct(
        #[Autowire(param: AiServices::CLAUDE_CODE_TOKENS)]
        #[SensitiveParameter]
        private array $tokens,
        private AccountCooldowns $cooldowns,
    ) {
    }

    #[Override]
    public function claudeCode(): AccountLogin
    {
        foreach ($this->tokens as $name => $token) {
            $account = new Account(Provider::ClaudeCode, $name);
            if (!$this->cooldowns->isCoolingDown($account)) {
                return new AccountLogin($account, $token);
            }
        }

        // Typed apart, because a wait brings nothing back when every key was rejected.
        throw $this->cooldowns->isEveryAccountRejected(Provider::ClaudeCode) ? new EveryKeyRejected(Provider::ClaudeCode) : new EveryAccountCoolingDown(Provider::ClaudeCode, max(1, $this->cooldowns->secondsUntilAnAccountIsFree(Provider::ClaudeCode)));
    }
}
