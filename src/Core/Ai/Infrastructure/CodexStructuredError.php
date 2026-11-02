<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\AccountError;
use Intakt\Core\Ai\Contract\AccountUnusable;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\RuntimeException;

// The closed set of the CLI's own errors that are about the account. `codex exec --json` drops the code the CLI keeps for each error (codex_error_info) and writes only its message, so each case is the start of the messages the CLI writes for it (0.160.0); only the turn.failed event is read, which the CLI writes and the model's text never reaches.
enum CodexStructuredError: string
{
    case UsageLimit = 'usage_limit';
    case ModelNotOnPlan = 'model_not_on_plan';
    case LoginLost = 'login_lost';

    public static function of(string $cliMessage): ?self
    {
        return array_find(self::cases(), static fn (self $error): bool => 1 === preg_match($error->messages(), $cliMessage));
    }

    // Without a reset, because the CLI writes its reset as a local clock time only (plan T40 reads the account's reset).
    public function failure(): RuntimeException
    {
        return match ($this) {
            self::UsageLimit => new RateLimitExceededException(),
            self::ModelNotOnPlan => new AccountUnusable(AccountError::ModelNotOnPlan),
            self::LoginLost => new AccountUnusable(AccountError::LoginLost),
        };
    }

    private function messages(): string
    {
        return match ($this) {
            self::UsageLimit => '/^(?:You’ve hit your usage limit|Quota exceeded\. Check your plan and billing details\.|Your workspace is out of credits\.|You hit your spend cap )/u',
            self::ModelNotOnPlan => '/^(?:To use Codex with your ChatGPT plan, upgrade|unexpected status 400 Bad Request: \{"detail":"The \'[^\']+\' model is not supported when using Codex with a ChatGPT account\.)/u',
            self::LoginLost => '/^(?:Your access token could not be refreshed|unexpected status 401 Unauthorized)/u',
        };
    }
}
