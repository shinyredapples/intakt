<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use SensitiveParameter;

// What a subscription CLI needs to reach the per-review server for one round: set by plain code beside the call, never by model text.
final readonly class ReviewServerAccess
{
    // The call option that carries it to the CLI client; only the CLI platform's client reads it.
    public const string OPTION = 'intakt_review_server';

    /**
     * @param non-empty-string $url
     * @param list<string>     $tools the read-only tools the server serves, by their names on the server
     */
    public function __construct(
        public string $url,
        #[SensitiveParameter]
        public string $credential,
        public array $tools,
    ) {
    }
}
