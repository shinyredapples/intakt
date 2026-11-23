<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class MarkdownSummary
{
    public function __construct(
        public int $words,
        public bool $opensPage,
    ) {
    }
}
