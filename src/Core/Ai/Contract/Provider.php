<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

enum Provider: string
{
    case Anthropic = 'anthropic';
    case OpenAi = 'openai';
    case OpenAiCompatible = 'openai_compatible';

    /**
     * @return list<string>
     */
    public function variables(): array
    {
        return match ($this) {
            self::Anthropic => ['ANTHROPIC_API_KEY'],
            self::OpenAi => ['OPENAI_API_KEY'],
            self::OpenAiCompatible => ['OPENAI_COMPATIBLE_BASE_URL', 'OPENAI_COMPATIBLE_API_KEY'],
        };
    }

    /**
     * @param list<string> $missing
     */
    public function disabledMessage(array $missing): string
    {
        return 'Provider "'.$this->value.'" is disabled: set '.implode(' and ', $missing).' to enable it.';
    }
}
