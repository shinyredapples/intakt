<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class HighlightAssembly
{
    /**
     * @param list<string> $findings
     */
    public function __construct(
        public string $text,
        public array $findings,
    ) {
    }
}
