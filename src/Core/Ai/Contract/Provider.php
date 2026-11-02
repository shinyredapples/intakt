<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

enum Provider: string
{
    case Anthropic = 'anthropic';
    case OpenAi = 'openai';
    case OpenAiCompatible = 'openai_compatible';
    case ClaudeCode = 'claude_code';
    case Codex = 'codex';

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
            // None, because Codex has no long-lived token: its credential is the login file Intakt keeps per account.
            self::Codex => [],
        };
    }

    // The subscription CLIs get no tool until the per-review MCP server serves them the fenced ones (plan T15), so they never see the change.
    public function readsTheCheckout(): bool
    {
        return !in_array($this, [self::ClaudeCode, self::Codex], true);
    }

    /**
     * @param list<string> $missing
     */
    public function disabledMessage(array $missing): string
    {
        return 'Provider "'.$this->value.'" is disabled: set '.implode(' and ', $missing).' to enable it.';
    }
}
