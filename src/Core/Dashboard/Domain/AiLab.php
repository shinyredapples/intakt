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

    // Null for an unknown lab, because a guessed lab would mislabel a reviewer; the page then shows the model name alone.
    public static function of(string $model): ?self
    {
        $id = strtolower(self::withoutHost($model));
        $name = strtolower(self::lastPart($id));

        return array_find(self::cases(), static fn (self $lab): bool => array_any(
            $lab->markers(),
            static fn (string $marker): bool => self::startsWith($id, $marker) || self::startsWith($name, $marker),
        ));
    }

    // Without the namespace and the hosting provider, because a reader new to AI reviews knows "Kimi K3.1 Pro", not "moonshotai/Kimi-K3.1-Pro:deepinfra".
    public static function modelName(string $model): string
    {
        $words = explode(' ', str_replace(['-', '_'], ' ', self::lastPart(self::withoutHost($model))));
        $name = implode(' ', array_map(static fn (string $word): string => match (strtolower($word)) {
            'gpt' => 'GPT',
            'glm' => 'GLM',
            default => ucfirst($word),
        }, $words));

        // Joined again, because a version written with dashes (4-5) would otherwise read as two numbers.
        return (string) preg_replace('/(?<= )(\d) (\d)(?= |$)/', '$1.$2', $name);
    }

    /**
     * @return non-empty-list<string>
     */
    private function markers(): array
    {
        return match ($this) {
            self::Anthropic => ['anthropic/', 'claude', 'opus', 'sonnet', 'haiku'],
            self::OpenAi => ['openai/', 'gpt'],
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

    // A family name only as a whole word, because "acme/gptish" is not a GPT; a namespace ends at its slash already.
    private static function startsWith(string $part, string $marker): bool
    {
        if (!str_starts_with($part, $marker)) {
            return false;
        }

        return str_ends_with($marker, '/') || 1 !== preg_match('/^[a-z]/', substr($part, strlen($marker)));
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
