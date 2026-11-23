<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class MarkdownSummary
{
    public function __construct(
        public int $words,
        public bool $opensPage,
    ) {
    }
}
