<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class Place
{
    public function __construct(
        public ?string $file = null,
        public ?int $line = null,
        public ?string $symbol = null,
    ) {
    }
}
