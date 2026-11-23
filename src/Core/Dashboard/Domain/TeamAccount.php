<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Provider;

final readonly class TeamAccount
{
    /**
     * @param list<TeamWindow> $windows
     */
    public function __construct(
        public Account $account,
        public TeamAccountState $state,
        public array $windows,
        public ?TeamSpend $spend,
    ) {
    }

    // The configured name, because an owner-chosen name has no source until accounts are database state.
    public function name(): string
    {
        return ucfirst(str_replace('_', ' ', $this->account->name));
    }

    public function kind(): string
    {
        return $this->account->provider->spendsAPlan() ? 'Subscription, '.$this->vendor() : 'API key, pay per use, '.$this->vendor();
    }

    // With its vendor, because two providers may each have an account of the same name and a role must tell them apart.
    public function label(): string
    {
        return $this->name().' ('.$this->vendor().')';
    }

    private function vendor(): string
    {
        return match ($this->account->provider) {
            Provider::Anthropic => 'Anthropic',
            Provider::OpenAi => 'OpenAI',
            Provider::OpenAiCompatible => 'OpenAI-compatible',
            Provider::ClaudeCode => 'Claude Code',
            Provider::Codex => 'Codex',
        };
    }
}
