<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// The AI lab behind a model, named from the model id's namespace or name, so a reviewer reads as "Kimi K3.1 Pro" by "Moonshot AI" instead of a raw provider id.
enum AiLab: string
{
    case Anthropic = 'Anthropic';
    case OpenAi = 'OpenAI';
    case Google = 'Google';
    case MoonshotAi = 'Moonshot AI';
    case DeepSeek = 'DeepSeek';
    case Qwen = 'Qwen (Alibaba)';
    case ZAi = 'Z.ai';
    case MistralAi = 'Mistral AI';
    case Meta = 'Meta';
    case XAi = 'xAI';

    // The first lab whose marker starts the id or its model name; an id of an unknown lab has none, and the page then shows the model name alone.
    public static function of(string $model): ?self
    {
        $id = strtolower(self::withoutHost($model));
        $name = strtolower(self::lastPart($id));

        return array_find(self::cases(), static fn (self $lab): bool => array_any(
            $lab->markers(),
            static fn (string $marker): bool => str_starts_with($id, $marker) || str_starts_with($name, $marker),
        ));
    }

    // The part after the namespace and before the hosting provider, with spaces for dashes, so "moonshotai/Kimi-K3.1-Pro:deepinfra" reads "Kimi K3.1 Pro".
    public static function modelName(string $model): string
    {
        $words = explode(' ', str_replace(['-', '_'], ' ', self::lastPart(self::withoutHost($model))));
        $name = implode(' ', array_map(static fn (string $word): string => match (strtolower($word)) {
            'gpt' => 'GPT',
            'glm' => 'GLM',
            default => ucfirst($word),
        }, $words));

        // A version written with dashes (claude-opus-4-5) reads as one number.
        return (string) preg_replace('/(?<= )(\d) (\d)(?= |$)/', '$1.$2', $name);
    }

    /**
     * @return non-empty-list<string>
     */
    private function markers(): array
    {
        return match ($this) {
            self::Anthropic => ['anthropic/', 'claude'],
            self::OpenAi => ['openai/', 'gpt', 'o3', 'o4', 'codex'],
            self::Google => ['google/', 'gemini', 'gemma'],
            self::MoonshotAi => ['moonshotai/', 'kimi'],
            self::DeepSeek => ['deepseek'],
            self::Qwen => ['qwen'],
            self::ZAi => ['zai-org/', 'z-ai/', 'glm'],
            self::MistralAi => ['mistralai/', 'mistral', 'codestral', 'devstral'],
            self::Meta => ['meta-llama/', 'llama'],
            self::XAi => ['x-ai/', 'xai/', 'grok'],
        };
    }

    private static function withoutHost(string $model): string
    {
        return explode(':', $model, 2)[0];
    }

    private static function lastPart(string $model): string
    {
        $slash = strrpos($model, '/');

        return false === $slash ? $model : substr($model, $slash + 1);
    }
}
