<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\AccountError;
use Intakt\Core\Ai\Contract\AccountUnusable;
use Intakt\Core\Ai\Infrastructure\CodexStructuredError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\RateLimitExceededException;

// Copied from the CLI's source at 0.160.0, because the exec stream carries only these messages and a live account error cannot be produced in a test.
#[CoversClass(CodexStructuredError::class)]
#[CoversClass(AccountUnusable::class)]
#[CoversClass(AccountError::class)]
final class CodexStructuredErrorTest extends TestCase
{
    #[DataProvider('cliMessages')]
    public function testEachMessageOfTheClosedSetNamesItsError(string $message, CodexStructuredError $error): void
    {
        self::assertSame($error, CodexStructuredError::of($message));
    }

    /**
     * @return iterable<string, array{string, CodexStructuredError}>
     */
    public static function cliMessages(): iterable
    {
        yield 'a usage limit' => ['You’ve hit your usage limit. Visit https://chatgpt.com/codex/settings/usage to purchase more credits or try again later.', CodexStructuredError::UsageLimit];
        yield 'a usage limit of one model' => ['You’ve hit your usage limit for GPT-5.3-Codex-Spark. Switch to another model now, or try again at 3:00 PM.', CodexStructuredError::UsageLimit];
        yield 'a spent quota' => ['Quota exceeded. Check your plan and billing details.', CodexStructuredError::UsageLimit];
        yield 'a workspace out of credits' => ['Your workspace is out of credits. Add credits to continue.', CodexStructuredError::UsageLimit];
        yield 'a spend cap' => ['You hit your spend cap set in your workspace. Increase your spend cap to continue.', CodexStructuredError::UsageLimit];
        yield 'Codex not on the plan' => ['To use Codex with your ChatGPT plan, upgrade to Plus: https://chatgpt.com/explore/plus.', CodexStructuredError::ModelNotOnPlan];
        yield 'a model not on the plan' => ['unexpected status 400 Bad Request: {"detail":"The \'gpt-5.5\' model is not supported when using Codex with a ChatGPT account."}', CodexStructuredError::ModelNotOnPlan];
        yield 'a revoked login' => ['Your access token could not be refreshed because your refresh token was revoked. Please log out and sign in again.', CodexStructuredError::LoginLost];
        yield 'a login signed out elsewhere' => ['Your access token could not be refreshed because you have since logged out or signed in to another account. Please sign in again.', CodexStructuredError::LoginLost];
        yield 'an unauthorized call' => ['unexpected status 401 Unauthorized: Missing bearer or basic authentication in header, url: https://chatgpt.com/backend-api/codex/responses', CodexStructuredError::LoginLost];
    }

    #[DataProvider('otherMessages')]
    public function testAnyOtherMessageIsNoStructuredError(string $message): void
    {
        self::assertNull(CodexStructuredError::of($message));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherMessages(): iterable
    {
        yield 'a cut-off stream that mentions a limit' => ['stream disconnected before completion: the usage limit message could not be read'];
        yield 'a limit mentioned after the start' => ['Reconnecting... 2/5 (You’ve hit your usage limit.)'];
        yield 'a rate limit of the API' => ['rate limit exceeded: try again in 20s'];
        yield 'another bad request' => ['unexpected status 400 Bad Request: {"detail":"Unsupported parameter: reasoning.effort"}'];
        yield 'a server error' => ['unexpected status 500 Internal Server Error: upstream failed'];
    }

    public function testAUsageLimitIsALimitWithoutAReset(): void
    {
        $failure = CodexStructuredError::UsageLimit->failure();

        self::assertInstanceOf(RateLimitExceededException::class, $failure);
        self::assertNull($failure->getRetryAfter());
    }

    public function testAModelNotOnThePlanIsAnAccountError(): void
    {
        $failure = CodexStructuredError::ModelNotOnPlan->failure();

        self::assertInstanceOf(AccountUnusable::class, $failure);
        self::assertSame(AccountError::ModelNotOnPlan, $failure->error);
    }

    public function testALostLoginIsAnAccountError(): void
    {
        $failure = CodexStructuredError::LoginLost->failure();

        self::assertInstanceOf(AccountUnusable::class, $failure);
        self::assertSame(AccountError::LoginLost, $failure->error);
    }

    public function testAnAccountErrorCarriesNoTextOfTheVendor(): void
    {
        self::assertSame('The vendor refuses the account until the owner acts: login_lost.', CodexStructuredError::LoginLost->failure()->getMessage());
    }
}
