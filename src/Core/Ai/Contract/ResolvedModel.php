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

    /**
     * @param positive-int $maxOutput
     */
    public function withMaxOutput(int $maxOutput): self
    {
        return clone ($this, ['maxOutput' => $maxOutput]);
    }
}
