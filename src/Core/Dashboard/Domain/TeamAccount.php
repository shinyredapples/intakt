<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;

final readonly class TeamAccount
{
    /**
     * @param list<TeamWindow> $windows
     * @param list<string>     $roles
     */
    public function __construct(
        public Account $account,
        public TeamAccountState $state,
        public array $windows,
        public array $roles,
    ) {
    }

    // The configured name, because an owner-chosen name has no source until accounts are database state.
    public function name(): string
    {
        return ucfirst(str_replace('_', ' ', $this->account->name));
    }

    public function kind(): string
    {
        $provider = match ($this->account->provider) {
            Provider::Anthropic => 'Anthropic',
            Provider::OpenAi => 'OpenAI',
            Provider::OpenAiCompatible => 'OpenAI-compatible',
            Provider::ClaudeCode => 'Claude Code',
            Provider::Codex => 'Codex',
        };

        return $this->account->provider->spendsAPlan() ? 'Subscription, '.$provider : 'API key, pay per use, '.$provider;
    }
}
