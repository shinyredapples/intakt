<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use SensitiveParameter;

// A subscription CLI calls the round's review server itself, because it cannot use Intakt's toolbox, so it needs the address, the round's credential and the tools it may call.
final readonly class ReviewServerAccess
{
    // A call option, because the platform passes options through to the CLI's client; every other platform drops it.
    public const string OPTION = 'intakt_review_server';

    /**
     * @param non-empty-string       $name
     * @param non-empty-string       $url
     * @param non-empty-list<string> $tools
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
