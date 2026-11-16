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

    // What the commands print: the line, or one line that says how to get the token, never silence.
    public function setup(): string
    {
        if (!$this->hasToken()) {
            return 'No MCP owner token found: set MCP_OWNER_TOKEN in .env.local and start again, or read the one the demo keeps in the data volume';
        }

        return 'Connect an MCP client: '.$this->line();
    }

    // The bearer header only with a token, because the owner server lets a client read without one.
    public function line(): string
    {
        $line = sprintf('claude mcp add --transport http intakt %s', $this->shellWord(rtrim($this->baseUrl, '/').'/mcp/owner'));

        return $this->hasToken() ? sprintf('%s --header %s', $line, $this->shellWord('Authorization: Bearer '.$this->ownerToken)) : $line;
    }

    // The owner pastes the line into a shell, so a value the shell would expand is single-quoted; a plain value keeps the familiar form.
    private function shellWord(string $value): string
    {
        if (1 === preg_match('#^[A-Za-z0-9:/._%@+=-]+\z#', $value)) {
            return $value;
        }

        if (1 === preg_match('#^Authorization: Bearer [A-Za-z0-9._~+/=-]+\z#', $value)) {
            return '"'.$value.'"';
        }

        return "'".str_replace("'", "'\\''", $value)."'";
    }
}
