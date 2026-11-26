<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class ReviewerRole
{
    // Reserved for the project's technical_writer section, so a panel member cannot take the docs lane by its name.
    public const string TECHNICAL_WRITER = 'technical_writer';

    /**
     * @param non-empty-string $name
     * @param non-empty-string $prompt
     */
    public function __construct(
        public string $name,
        public string $prompt,
    ) {
    }
}
