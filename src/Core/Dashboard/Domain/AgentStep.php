<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// Knows only whether the owner token is set, because the page must never show the token itself.
final readonly class AgentStep
{
    public function __construct(
        public string $address,
        public bool $hasToken,
        public bool $allowsWrites,
        public string $composeService,
    ) {
    }

    public function state(): StepState
    {
        return $this->hasToken ? StepState::Done : StepState::Missing;
    }

    public function sentence(): string
    {
        if (!$this->hasToken) {
            return 'Your agent can connect and read without a token; to let it start reviews, set MCP_OWNER_TOKEN, and MCP_OWNER_ACCESS to mcp_tool_write, in .env.local.';
        }

        return $this->allowsWrites
            ? 'Your agent connects with the owner token and may read and start reviews.'
            : 'Your agent connects with the owner token; it may only read until MCP_OWNER_ACCESS is mcp_tool_write.';
    }

    public function clientLine(): string
    {
        $line = 'claude mcp add --transport http intakt '.$this->address;

        return $this->hasToken ? $line.' --header "Authorization: Bearer <the token>"' : $line;
    }

    // Only in the compose demo, because there the start log holds the client line with the token the first start made.
    public function tokenCommand(): ?string
    {
        return '' === $this->composeService ? null : sprintf('docker compose logs %s | grep "claude mcp add"', $this->composeService);
    }
}
