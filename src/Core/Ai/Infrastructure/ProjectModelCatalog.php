<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Generator;
use Intakt\Core\Ai\Contract\Provider;
use Symfony\AI\Platform\Bridge\Anthropic\Claude;
use Symfony\AI\Platform\Bridge\ClaudeCode\ClaudeCode;
use Symfony\AI\Platform\Bridge\Codex\Codex;
use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\Bridge\Generic\FallbackModelCatalog;
use Symfony\AI\Platform\Bridge\OpenAi\Gpt;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;

// The AI Bundle's model_catalog entries for the models projects map to a provider; a model the bridge's own catalog lists keeps its entry, since that knows the model's real capabilities.
final readonly class ProjectModelCatalog
{
    // What the review calls need: a conversation, a structured verdict and read-only tools (plan T5, T7).
    private const array CAPABILITIES = [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::OUTPUT_STRUCTURED, Capability::TOOL_CALLING];

    /**
     * @param array<string, array{tiers: array<string, array{provider: string, model: non-empty-string, substitutes?: list<array{provider: string, model: non-empty-string}>}>}> $projects
     */
    public function __construct(
        private array $projects,
    ) {
    }

    /**
     * @return array<non-empty-string, array{class: class-string<Model>, capabilities: list<Capability>}>
     */
    public function models(Provider $provider): array
    {
        $known = $this->bridgeCatalog($provider)->getModels();
        $models = [];

        foreach ($this->mappedModels($provider) as $model) {
            if (!isset($known[$model])) {
                $models[$model] = ['class' => $this->modelClass($provider), 'capabilities' => self::CAPABILITIES];
            }
        }

        return $models;
    }

    private function bridgeCatalog(Provider $provider): ModelCatalogInterface
    {
        return match ($provider) {
            Provider::Anthropic => new AnthropicBridgeCatalog()->catalog(),
            Provider::OpenAi => new OpenAiBridgeCatalog()->catalog(),
            // Not the bridge's own catalog: its models lack structured output, which the verdict needs.
            Provider::OpenAiCompatible, Provider::ClaudeCode, Provider::Codex => new FallbackModelCatalog(),
        };
    }

    /**
     * @return list<non-empty-string>
     */
    private function mappedModels(Provider $provider): array
    {
        $models = [];

        foreach ($this->mappings() as $mapping) {
            if ($provider->value === $mapping['provider']) {
                $models[] = $mapping['model'];
            }
        }

        return array_values(array_unique($models));
    }

    /**
     * @return Generator<int, array{provider: string, model: non-empty-string}>
     */
    private function mappings(): Generator
    {
        foreach ($this->projects as $project) {
            foreach ($project['tiers'] as $tier) {
                yield $tier;
                yield from $tier['substitutes'] ?? [];
            }
        }
    }

    /**
     * @return class-string<Model>
     */
    private function modelClass(Provider $provider): string
    {
        return match ($provider) {
            Provider::Anthropic => Claude::class,
            Provider::OpenAi => Gpt::class,
            Provider::OpenAiCompatible => CompletionsModel::class,
            Provider::ClaudeCode => ClaudeCode::class,
            Provider::Codex => Codex::class,
        };
    }
}
