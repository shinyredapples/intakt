<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

final readonly class ResolvedModel
{
    /**
     * @param non-empty-string  $model
     * @param positive-int|null $maxOutput
     */
    public function __construct(
        public Provider $provider,
        public string $model,
        public ?int $maxOutput = null,
    ) {
    }
}
