<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use DateTimeImmutable;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(InstallationTokens::class)]
#[UsesClass(InstallationToken::class)]
#[UsesClass(InstallationTokenNotMinted::class)]
#[UsesClass(InstallationTokenMinter::class)]
#[UsesClass(AppJwt::class)]
final class InstallationTokensTest extends TestCase
{
    private MockClock $clock;
    private int $exchanges = 0;
    private bool $refuse = false;
    private InstallationTokens $tokens;

    protected function setUp(): void
    {
        $this->clock = new MockClock(new DateTimeImmutable('2026-11-25 10:00:00 UTC'));
        $http = new MockHttpClient(function (): ResponseInterface {
            ++$this->exchanges;
            if ($this->refuse) {
                return new MockResponse('{"message":"Bad credentials"}', ['http_code' => 401]);
            }

            return new MockResponse(json_encode(['token' => 'value-'.$this->exchanges, 'expires_at' => '2026-11-25T11:00:00Z'], JSON_THROW_ON_ERROR), ['http_code' => 201]);
        });
        $jwt = new AppJwt('12345', AppKey::generate()->private, $this->clock);
        $this->tokens = new InstallationTokens(new InstallationTokenMinter('678', $jwt, $http, new NullLogger()), $this->clock);
    }

    public function testAValidTokenIsReusedWithoutAnotherExchange(): void
    {
        $first = $this->tokens->current();
        $this->clock->modify('+30 minutes');

        self::assertSame($first->value, $this->tokens->current()->value);
        self::assertSame(1, $this->exchanges);
    }

    public function testAnExpiredTokenIsMintedAgain(): void
    {
        $first = $this->tokens->current();
        $this->clock->modify('+61 minutes');

        self::assertNotSame($first->value, $this->tokens->current()->value);
        self::assertSame(2, $this->exchanges);
    }

    public function testATokenAboutToLapseIsMintedAgainBeforeTheCall(): void
    {
        $this->tokens->current();
        $this->clock->modify('+59 minutes 30 seconds');

        $this->tokens->current();

        self::assertSame(2, $this->exchanges);
    }

    public function testAFailedExchangeFailsTheCallAndKeepsNoStaleToken(): void
    {
        $this->tokens->current();
        $this->clock->modify('+2 hours');
        $this->refuse = true;

        try {
            $this->tokens->current();
            self::fail('A refused exchange must fail the call.');
        } catch (InstallationTokenNotMinted) {
            $this->refuse = false;
        }

        self::assertSame('value-3', $this->tokens->current()->value);
    }

    public function testAResetForgetsTheToken(): void
    {
        $this->tokens->current();

        $this->tokens->reset();
        $this->tokens->current();

        self::assertSame(2, $this->exchanges);
    }
}
