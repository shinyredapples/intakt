<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Infrastructure\CodexLogin;
use Intakt\Core\Ai\Infrastructure\CodexLoginUnusable;
use Intakt\Core\Ai\Infrastructure\CodexTokenRefresh;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(CodexTokenRefresh::class)]
#[UsesClass(CodexLogin::class)]
#[UsesClass(CodexLoginUnusable::class)]
final class CodexTokenRefreshTest extends TestCase
{
    public function testTheRefreshTokenIsSentTheWayTheCliSendsIt(): void
    {
        $answer = new JsonMockResponse(['access_token' => 'access-2']);

        $this->refresh($answer)->refreshedIfDue($this->dueLogin());

        $body = $answer->getRequestOptions()['body'];
        self::assertIsString($body);
        self::assertSame(['POST', 'https://auth.openai.com/oauth/token'], [$answer->getRequestMethod(), $answer->getRequestUrl()]);
        self::assertSame(
            ['client_id' => 'app_EMoamEEZ73f0CkXaXp7hrann', 'grant_type' => 'refresh_token', 'refresh_token' => 'refresh-1', 'scope' => 'openid profile email'],
            json_decode($body, true),
        );
    }

    public function testOnlyTheTokensOfTheAnswerAreKept(): void
    {
        $answer = new JsonMockResponse(['access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'expires_in' => 3600]);

        $refreshed = $this->refresh($answer)->refreshedIfDue($this->dueLogin());

        self::assertSame(
            ['id_token' => 'id-1', 'access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'account_id' => 'acct'],
            json_decode((string) $refreshed?->json(), true)['tokens'] ?? null,
        );
    }

    public function testALoginThatIsNotDueIsNotRefreshed(): void
    {
        $http = new MockHttpClient();

        $refreshed = new CodexTokenRefresh($http, new MockClock('2026-11-02 12:00:00', 'UTC'))->refreshedIfDue(CodexLogin::fromJson(CodexLoginTest::json('2026-11-01T00:00:00Z')));

        self::assertNull($refreshed);
        self::assertSame(0, $http->getRequestsCount());
    }

    /**
     * @return iterable<string, array{MockResponse}>
     */
    public static function unusableAnswers(): iterable
    {
        yield 'a refused refresh' => [new JsonMockResponse(['error' => 'invalid_grant'], ['http_code' => 401])];
        yield 'no JSON' => [new MockResponse('<html>')];
        yield 'no access token' => [new JsonMockResponse(['id_token' => 'id-2'])];
        yield 'an empty access token' => [new JsonMockResponse(['access_token' => ''])];
        yield 'a refresh token that is no string' => [new JsonMockResponse(['access_token' => 'access-2', 'refresh_token' => 5])];
    }

    #[DataProvider('unusableAnswers')]
    public function testAnAnswerWithoutUsableTokensFailsTheRefresh(MockResponse $answer): void
    {
        $this->expectException(CodexLoginUnusable::class);

        $this->refresh($answer)->refreshedIfDue($this->dueLogin());
    }

    private function refresh(MockResponse $answer): CodexTokenRefresh
    {
        return new CodexTokenRefresh(new MockHttpClient($answer), new MockClock('2026-11-02 12:00:00', 'UTC'));
    }

    private function dueLogin(): CodexLogin
    {
        return CodexLogin::fromJson(CodexLoginTest::json('2026-10-01T00:00:00Z'));
    }
}
