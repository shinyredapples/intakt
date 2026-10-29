<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

final readonly class TierRequest
{
    /**
     * @param array<mixed>|string|object $input
     * @param array<string, mixed>       $options
     */
    public function __construct(
        public array|string|object $input,
        public array $options = [],
    ) {
    }
}
