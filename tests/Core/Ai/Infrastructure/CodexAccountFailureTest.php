<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Infrastructure\CodexAccountFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;

#[CoversClass(CodexAccountFailure::class)]
final class CodexAccountFailureTest extends TestCase
{
    /**
     * @return iterable<string, array{string, CodexAccountFailure}>
     */
    public static function messagesOfTheCli(): iterable
    {
        yield 'usage limit' => ['You’ve hit your usage limit. Try again at 3:00 PM.', CodexAccountFailure::UsageLimitReached];
        yield 'usage limit, older apostrophe' => ["You've hit your usage limit. Try again in 3 hours.", CodexAccountFailure::UsageLimitReached];
        yield 'usage limit of one model' => ['You’ve hit your usage limit for gpt-5.3-codex. Switch to another model now, or try again later.', CodexAccountFailure::UsageLimitReached];
        yield 'quota exceeded' => ['Quota exceeded. Check your plan and billing details.', CodexAccountFailure::UsageLimitReached];
        yield 'plan without Codex' => ['To use Codex with your ChatGPT plan, upgrade to Plus: https://example.com/plus.', CodexAccountFailure::ModelNotInPlan];
        yield 'model not in the plan' => ['unexpected status 400 Bad Request: {"detail":"The \'gpt-5.3-codex\' model is not supported when using Codex with a ChatGPT account."}', CodexAccountFailure::ModelNotInPlan];
        yield 'refresh token expired' => ['Your access token could not be refreshed because your refresh token has expired. Please log out and sign in again.', CodexAccountFailure::LoginLost];
        yield 'refresh failed' => ['Your access token could not be refreshed. Please log out and sign in again.', CodexAccountFailure::LoginLost];
        yield 'unauthorized' => ['unexpected status 401 Unauthorized: Unknown error, request id: abc', CodexAccountFailure::LoginLost];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherMessages(): iterable
    {
        yield 'a limit mentioned later' => ['stream disconnected before completion: the usage limit message could not be read'];
        yield 'quota exceeded with more text' => ['Quota exceeded. Check your plan and billing details. And more.'];
        yield 'another bad request' => ['unexpected status 400 Bad Request: {"detail":"Unsupported parameter."}'];
        yield 'a server error' => ['unexpected status 500 Internal Server Error: Unknown error'];
        yield 'empty' => [''];
    }

    #[DataProvider('messagesOfTheCli')]
    public function testAFixedMessageOfTheCliNamesItsAccountFailure(string $message, CodexAccountFailure $failure): void
    {
        self::assertSame($failure, CodexAccountFailure::of($message));
    }

    #[DataProvider('otherMessages')]
    public function testAnyOtherMessageIsNoAccountFailure(string $message): void
    {
        self::assertNull(CodexAccountFailure::of($message));
    }

    public function testAUsageLimitIsAPlatformLimitWithoutAReset(): void
    {
        $exception = CodexAccountFailure::UsageLimitReached->exception();

        self::assertInstanceOf(RateLimitExceededException::class, $exception);
        self::assertNull($exception->getRetryAfter());
    }

    public function testAModelNotInThePlanIsAPlatformRejection(): void
    {
        self::assertSame(AuthenticationException::class, CodexAccountFailure::ModelNotInPlan->exception()::class);
    }

    public function testALostLoginIsAPlatformRejectionNamingItsCode(): void
    {
        $exception = CodexAccountFailure::LoginLost->exception();

        self::assertSame(AuthenticationException::class, $exception::class);
        self::assertSame('The Codex account cannot be used: login_lost.', $exception->getMessage());
    }
}
