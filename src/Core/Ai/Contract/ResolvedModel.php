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

    // Same provider and the same model before the pinned router provider, so a pinned fallback of the same model is told apart from another model on that provider, which is a substitute. Only PinnedProvider's `owner/model:provider` form pins, so `llama3:8b` and `llama3:70b`, or two `ft:` models, stay two models.
    public function isTheSameModelAs(self $other): bool
    {
        return $this->provider === $other->provider && $this->modelPart() === $other->modelPart();
    }

    private function modelPart(): string
    {
        return PinnedProvider::parts($this->model)[0] ?? $this->model;
    }
}
