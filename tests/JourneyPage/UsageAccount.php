<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

// The page names an account by vendor and number, never by its email or plan, so a fixed list keeps every name public-safe.
enum UsageAccount: string
{
    case Claude1 = 'claude-1';
    case Claude2 = 'claude-2';
    case ClaudeApiKey = 'claude-api';
    case GptSubscription = 'gpt-1';

    public function label(): string
    {
        return match ($this) {
            self::Claude1 => 'Claude account 1',
            self::Claude2 => 'Claude account 2',
            self::ClaudeApiKey => 'Claude API key',
            self::GptSubscription => 'GPT subscription',
        };
    }
}
