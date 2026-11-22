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

    // Same provider and the same model before the colon, where a router names its pinned provider, so a pinned fallback of the same model is told apart from another model on that provider, which is a substitute.
    public function isTheSameModelAs(self $other): bool
    {
        return $this->provider === $other->provider && $this->modelPart() === $other->modelPart();
    }

    private function modelPart(): string
    {
        return explode(':', $this->model, 2)[0];
    }
}
