<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

use SensitiveParameter;

final readonly class ReviewServerAccess
{
    // Carried as a call option because only the subscription CLI's client reads it; the agent's own options never name it.
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
}
