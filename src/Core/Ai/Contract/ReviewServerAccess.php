<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use SensitiveParameter;

// What a subscription CLI needs to reach the per-review server for one round, set by plain code beside the call because model text must never choose it.
final readonly class ReviewServerAccess
{
    // The call option that carries it to the CLI client, because that is the only client that reads it.
    public const string OPTION = 'intakt_review_server';

    /**
     * @param non-empty-string $url
     * @param list<string>     $tools
     */
    public function __construct(
        public string $url,
        #[SensitiveParameter]
        public string $credential,
        public array $tools,
    ) {
    }
}
