<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class ScanTarget
{
    /**
     * @param list<string> $options
     */
    public function __construct(
        public string $source,
        public array $options,
    ) {
    }
}
