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

    public function variables(): EnvironmentVariables
    {
        return match ($this) {
            self::Anthropic => new EnvironmentVariables('ANTHROPIC_API_KEY'),
            self::OpenAi => new EnvironmentVariables('OPENAI_API_KEY'),
            self::OpenAiCompatible => new EnvironmentVariables('OPENAI_COMPATIBLE_BASE_URL', 'OPENAI_COMPATIBLE_API_KEY'),
            self::ClaudeCode => new EnvironmentVariables('CLAUDE_CODE_OAUTH_TOKEN'),
            // None, because Codex has no long-lived token: its credential is the login file Intakt keeps per account.
            self::Codex => new EnvironmentVariables(),
        };
    }

    // False for the subscription CLIs, because the provider alone gives them no tool: their call reads the change only when the vendor gives it the per-review MCP server.
    public function readsTheCheckout(): bool
    {
        return !in_array($this, [self::ClaudeCode, self::Codex], true);
    }

    // Not the subscription CLIs, because their bridges answer in a final-result form and a streamed run ends differently: a killed process raises instead of giving an incomplete answer, and an answer after the CLI's own tools has no text.
    public function streams(): bool
    {
        return !in_array($this, [self::ClaudeCode, self::Codex], true);
    }

    // Only the subscription CLIs, because their accounts spend a plan's usage windows, so a limit without a reset means the plan is spent until a window resets.
    public function spendsAPlan(): bool
    {
        return !$this->readsTheCheckout();
    }

    // Not the subscription CLIs, because their bridges take no output limit option.
    public function limitsOutput(): bool
    {
        return $this->readsTheCheckout();
    }

    // Needed because Anthropic's usage counts cache reads and writes apart from input_tokens, also through the Claude Code CLI, while the OpenAI forms count cached tokens within the prompt.
    public function countsCacheApartFromPrompt(): bool
    {
        return in_array($this, [self::Anthropic, self::ClaudeCode], true);
    }

    public function disabledMessage(EnvironmentVariables $missing): string
    {
        return 'Provider "'.$this->value.'" is disabled: set '.implode(' and ', [...$missing]).' to enable it.';
    }

    public function platformId(Account $account): string
    {
        return AiServices::PLATFORM_PREFIX.$this->value.$this->suffixOf($account);
    }

    public function cliId(Account $account): string
    {
        return AiServices::PREFIX.$this->value.'.cli'.$this->suffixOf($account);
    }

    public function accountsId(): string
    {
        return AiServices::ACCOUNTS_PREFIX.$this->value;
    }

    public function configKey(): string
    {
        return $this->value;
    }

    public function parameter(): string
    {
        return AiServices::PREFIX.$this->value;
    }

    private function suffixOf(Account $account): string
    {
        return Account::DEFAULT === $account->name ? '' : '.'.$account->name;
    }
}
