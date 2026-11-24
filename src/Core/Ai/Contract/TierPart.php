<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

final readonly class TierPart
{
    public function __construct(
        public int|string $key,
        public mixed $value,
    ) {
    }
}
