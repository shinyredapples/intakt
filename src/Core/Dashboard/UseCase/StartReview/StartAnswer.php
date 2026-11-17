<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\StartReview;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The round apart from the line, so the first-run start can open the started review's live page; excluded from the container because ReviewStarts builds one per start.
#[Exclude]
final readonly class StartAnswer
{
    /**
     * @param array{string, string} $line
     */
    public function __construct(
        public array $line,
        public ?string $round = null,
    ) {
    }
}
