<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class Evidence
{
    public function __construct(
        public string $probe,
        public string $output,
    ) {
    }
}
