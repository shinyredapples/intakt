<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

// Pinned in the owner's config, so a call never runs on whatever model or effort the CLI defaults to that day.
final readonly class CodexSettings
{
    /**
     * @param non-empty-string $model
     */
    public function __construct(
        public string $model,
        public CodexEffort $effort,
    ) {
    }

    /**
     * @param array{model: non-empty-string, effort: string} $configuration
     */
    public static function fromConfiguration(array $configuration): self
    {
        return new self($configuration['model'], CodexEffort::from($configuration['effort']));
    }

    // In the bridge's option form, which turns each key into the CLI flag of the same name.
    /**
     * @return array{model: non-empty-string, config: list<string>}
     */
    public function options(): array
    {
        return ['model' => $this->model, 'config' => ['model_reasoning_effort="'.$this->effort->value.'"']];
    }
}
