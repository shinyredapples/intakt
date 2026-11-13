<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\RuntimeException;

// The CLI's JSONL keeps only the message of an error event and drops its code, so the fixed messages the CLI writes for these codes are the closed set; any other message stays a failure of the reviewer.
enum CodexAccountFailure: string
{
    case UsageLimitReached = 'usage_limit_reached';
    case ModelNotInPlan = 'model_not_in_plan';
    case LoginLost = 'login_lost';

    public static function of(string $message): ?self
    {
        return array_find(self::cases(), static fn (self $failure): bool => 1 === preg_match($failure->pattern(), $message));
    }

    // Typed as the platform's own limit and rejection, so AccountPlatform cools the account down or takes it out as for any provider.
    public function exception(): RuntimeException
    {
        return match ($this) {
            self::UsageLimitReached => new RateLimitExceededException(errorMessage: 'The Codex plan reached its usage limit.'),
            self::ModelNotInPlan, self::LoginLost => new AuthenticationException(sprintf('The Codex account cannot be used: %s.', $this->value)),
        };
    }

    // Anchored at the start, so a message that only mentions one of them somewhere is no account failure.
    private function pattern(): string
    {
        return match ($this) {
            self::UsageLimitReached => '/^(You[’\']ve hit your usage limit\b|Quota exceeded\. Check your plan and billing details\.$)/u',
            self::ModelNotInPlan => '/^(To use Codex with your ChatGPT plan, upgrade to Plus\b|unexpected status 400 Bad Request: \{"detail":"The \'[^\']+\' model is not supported when using Codex with a ChatGPT account\.)/u',
            self::LoginLost => '/^(Your access token could not be refreshed\b|unexpected status 401 Unauthorized\b)/u',
        };
    }
}
