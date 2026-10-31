<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

enum Provider: string
{
    case Anthropic = 'anthropic';
    case OpenAi = 'openai';
    case OpenAiCompatible = 'openai_compatible';
    case ClaudeCode = 'claude_code';

    // The key of the deferred result's metadata that names the substitute provider which answered in place of the tier's own.
    public const string SUBSTITUTE_METADATA = 'intakt_substitute';

    /**
     * @return list<string>
     */
    public function variables(): array
    {
        return match ($this) {
            self::Anthropic => ['ANTHROPIC_API_KEY'],
            self::OpenAi => ['OPENAI_API_KEY'],
            self::OpenAiCompatible => ['OPENAI_COMPATIBLE_BASE_URL', 'OPENAI_COMPATIBLE_API_KEY'],
            self::ClaudeCode => ['CLAUDE_CODE_OAUTH_TOKEN'],
        };
    }

    // The subscription CLI gets no tool until the per-review MCP server serves it the fenced ones (plan T15), so it never sees the change.
    public function readsTheCheckout(): bool
    {
        return self::ClaudeCode !== $this;
    }

    /**
     * @param list<string> $missing
     */
    public function disabledMessage(array $missing): string
    {
        return 'Provider "'.$this->value.'" is disabled: set '.implode(' and ', $missing).' to enable it.';
    }
}
