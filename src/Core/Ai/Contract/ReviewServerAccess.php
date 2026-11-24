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
     * @param non-empty-string $name
     * @param non-empty-string $url
     */
    public function __construct(
        public string $name,
        public string $url,
        #[SensitiveParameter]
        public string $bearer,
        public ReviewTools $tools,
    ) {
    }

    // Written as the JSON the CLI reads, because the file is its only use and a nested array would cross the module as an untyped shape.
    public function mcpConfigurationJson(): string
    {
        $configuration = ['mcpServers' => [$this->name => ['type' => 'http', 'url' => $this->url, 'headers' => ['Authorization' => 'Bearer '.$this->bearer]]]];

        return json_encode($configuration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    // The names the Claude Code CLI gives this server's tools, so the tools it is allowed and the reads it reports are matched by the same names.
    public function mcpToolNames(): ReviewTools
    {
        return new ReviewTools(...array_map(fn (string $tool): string => sprintf('mcp__%s__%s', $this->name, $tool), [...$this->tools]));
    }
}
