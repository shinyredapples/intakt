<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Core\Ai\Infrastructure\CodexLogin;
use Intakt\Core\Ai\Infrastructure\CodexLoginUnusable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(CodexLogin::class)]
#[UsesClass(CodexLoginUnusable::class)]
final class CodexLoginTest extends TestCase
{
    public function testALoginRefreshedSixDaysAgoIsNotDue(): void
    {
        $login = CodexLogin::fromJson(self::json('2026-10-27T12:00:00.123456789Z'));

        self::assertFalse($login->isDueAt($this->moment('2026-11-02T12:00:00Z')));
    }

    public function testALoginRefreshedSevenDaysAgoIsDue(): void
    {
        $login = CodexLogin::fromJson(self::json('2026-10-26T12:00:00Z'));

        self::assertTrue($login->isDueAt($this->moment('2026-11-02T12:00:00Z')));
    }

    public function testARefreshReplacesTheTokensItGotAndKeepsTheRest(): void
    {
        $login = CodexLogin::fromJson(self::json('2026-10-01T00:00:00Z'));

        $refreshed = $login->refreshed(['access_token' => 'access-2', 'id_token' => 'id-2'], $this->moment('2026-11-02T12:00:00Z'));

        self::assertSame(
            ['OPENAI_API_KEY' => null, 'tokens' => ['id_token' => 'id-2', 'access_token' => 'access-2', 'refresh_token' => 'refresh-1', 'account_id' => 'acct'], 'last_refresh' => '2026-11-02T12:00:00Z'],
            json_decode($refreshed->json(), true),
        );
        self::assertSame('refresh-1', $refreshed->refreshToken());
        self::assertFalse($refreshed->isDueAt($this->moment('2026-11-02T12:00:00Z')));
    }

    public function testAnEmptyObjectInTheLoginStaysAnObject(): void
    {
        $login = CodexLogin::fromJson($this->jsonWithEmptyObject('2026-10-30T12:00:00Z'));

        self::assertEquals(new stdClass(), $this->extraOf($login->json()));
    }

    public function testAnEmptyObjectInTheLoginStaysAnObjectAfterARefresh(): void
    {
        $login = CodexLogin::fromJson($this->jsonWithEmptyObject('2026-10-01T00:00:00Z'));

        $refreshed = $login->refreshed(['access_token' => 'access-2'], $this->moment('2026-11-02T12:00:00Z'));

        self::assertEquals(new stdClass(), $this->extraOf($refreshed->json()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableLogins(): iterable
    {
        yield 'no JSON' => ['{'];
        yield 'no tokens' => ['{"last_refresh": "2026-10-01T00:00:00Z"}'];
        yield 'an empty refresh token' => [str_replace('refresh-1', '', self::json('2026-10-01T00:00:00Z'))];
        yield 'a token that is no string' => [str_replace('"access-1"', '5', self::json('2026-10-01T00:00:00Z'))];
        yield 'no last refresh' => ['{"tokens": {"id_token": "a", "access_token": "b", "refresh_token": "c"}}'];
        yield 'an unreadable last refresh' => [self::json('yesterday')];
        yield 'a last refresh with a NUL byte' => [self::json("2026-10-01T00:00:00Z\0")];
    }

    #[DataProvider('unusableLogins')]
    public function testALoginItCannotReadIsRefused(string $json): void
    {
        $this->expectException(CodexLoginUnusable::class);

        CodexLogin::fromJson($json);
    }

    public static function json(string $lastRefresh): string
    {
        return json_encode([
            'OPENAI_API_KEY' => null,
            'tokens' => ['id_token' => 'id-1', 'access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'account_id' => 'acct'],
            'last_refresh' => $lastRefresh,
        ], JSON_THROW_ON_ERROR);
    }

    private function jsonWithEmptyObject(string $lastRefresh): string
    {
        return '{"extra":{},"tokens":{"id_token":"id-1","access_token":"access-1","refresh_token":"refresh-1"},"last_refresh":"'.$lastRefresh.'"}';
    }

    private function extraOf(string $json): mixed
    {
        $contents = json_decode($json, flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $contents);

        return $contents->extra ?? null;
    }

    private function moment(string $moment): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $moment, new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class, $parsed);

        return $parsed;
    }
}
