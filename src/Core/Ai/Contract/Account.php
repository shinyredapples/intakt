<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

// One set of credentials of a provider; a provider's accounts are tried in the owner's order, and each keeps its own cooldown.
final readonly class Account
{
    public const string DEFAULT = 'default';
    public const string NAME_PATTERN = '/^[a-z][a-z0-9_]*$/';

    // The key of the deferred result's metadata that names the account a round ran on, so its usage row says so.
    public const string METADATA = 'intakt_account';

    public function __construct(
        public Provider $provider,
        public string $name,
    ) {
    }

    // A dot joins them because neither a provider value nor an account name (NAME_PATTERN) can hold one.
    public function key(): string
    {
        return $this->provider->value.'.'.$this->name;
    }

    /**
     * @return list<string>
     */
    public function variables(): array
    {
        if (self::DEFAULT === $this->name) {
            return $this->provider->variables();
        }

        return array_map(fn (string $variable): string => $variable.'_'.strtoupper($this->name), $this->provider->variables());
    }

    /**
     * @param list<string> $missing
     */
    public function disabledMessage(array $missing): string
    {
        return 'Account "'.$this->name.'" of provider "'.$this->provider->value.'" is disabled: set '.implode(' and ', $missing).' to enable it.';
    }
}
