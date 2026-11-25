<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

use DateTimeImmutable;

// Untrusted text the builder wrote, so a reader escapes and redacts it before a person sees it and never hands it to a model.
final readonly class ParkedQuestion
{
    /**
     * @param non-empty-list<string> $choices
     */
    public function __construct(
        public string $question,
        public array $choices,
        public string $recommendation,
        public DateTimeImmutable $parkedAt,
    ) {
    }
}
