<?php

declare(strict_types=1);

namespace Intakt\Core\Mcp\Contract;

use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// One home for the line an owner pastes to connect Claude Code, because intakt:sample:show and intakt:demo both print it; built by its caller from the environment it reads.
#[Exclude]
final readonly class OwnerClientLine
{
    public function __construct(
        private string $baseUrl,
        #[SensitiveParameter]
        private string $ownerToken,
    ) {
    }

    public function hasToken(): bool
    {
        return '' !== $this->ownerToken;
    }

    // The bearer header only with a token, because the owner server lets a client read without one.
    public function line(): string
    {
        $line = sprintf('claude mcp add --transport http intakt %s/mcp/owner', rtrim($this->baseUrl, '/'));

        return $this->hasToken() ? sprintf('%s --header "Authorization: Bearer %s"', $line, $this->ownerToken) : $line;
    }
}
