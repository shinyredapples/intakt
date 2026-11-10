<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use SensitiveParameter;

// The way a subscription CLI reaches the round's review server: the server's address, the credential of the round and the names of the read-only tools it may call.
final readonly class ReviewServerAccess
{
    // The call option that carries it, dropped by every platform but the CLI's, which turns it into flags and a configuration file.
    public const string OPTION = 'intakt_review_server';

    /**
     * @param non-empty-string       $name  the server's key in the CLI's MCP configuration
     * @param non-empty-string       $url
     * @param non-empty-list<string> $tools the server's own tool names, without the CLI's prefix
     */
    public function __construct(
        public string $name,
        public string $url,
        #[SensitiveParameter]
        public string $bearer,
        public array $tools,
    ) {
    }

    /**
     * @return array{mcpServers: array<string, array{type: string, url: string, headers: array{Authorization: string}}>}
     */
    public function mcpConfiguration(): array
    {
        return ['mcpServers' => [$this->name => ['type' => 'http', 'url' => $this->url, 'headers' => ['Authorization' => 'Bearer '.$this->bearer]]]];
    }
}
