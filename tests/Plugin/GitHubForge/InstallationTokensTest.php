<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

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
    private string $lifetime = '+1 hour';
    private InstallationTokens $tokens;

    public function testAFreshlyMintedTokenThatIsAlreadyExpiredFailsTheCall(): void
    {
        $this->lifetime = '-1 hour';

        $this->expectException(InstallationTokenNotMinted::class);

        (void) $this->tokens->current();
    }

    public function testAFreshlyMintedTokenAboutToLapseFailsTheCall(): void
    {
        $this->lifetime = '+30 seconds';

        $this->expectException(InstallationTokenNotMinted::class);

        (void) $this->tokens->current();
    }

    public function testAFreshlyMintedTokenWithMoreThanAMinuteToLiveIsUsed(): void
    {
        $this->lifetime = '+61 seconds';

        self::assertSame('value-1', $this->tokens->current()->value);
    }

    public function testAFreshlyMintedTokenThatExpiredIsNotKeptForTheNextCall(): void
    {
        $this->lifetime = '-1 hour';
        try {
            (void) $this->tokens->current();
        } catch (InstallationTokenNotMinted) {
            $this->lifetime = '+1 hour';
        }

        self::assertSame('value-2', $this->tokens->current()->value);
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
        (void) $this->tokens->current();
        $this->clock->modify('+59 minutes 30 seconds');

        (void) $this->tokens->current();

        self::assertSame(2, $this->exchanges);
    }

    public function testAFailedExchangeFailsTheCallAndKeepsNoStaleToken(): void
    {
        (void) $this->tokens->current();
        $this->clock->modify('+2 hours');
        $this->refuse = true;

        try {
            (void) $this->tokens->current();
            self::fail('A refused exchange must fail the call.');
        } catch (InstallationTokenNotMinted) {
            $this->refuse = false;
        }

        self::assertSame('value-3', $this->tokens->current()->value);
    }

    public function testAResetForgetsTheToken(): void
    {
        (void) $this->tokens->current();

        $this->tokens->reset();
        (void) $this->tokens->current();

        self::assertSame(2, $this->exchanges);
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-25 10:00:00 UTC');
        $http = new MockHttpClient(function (): ResponseInterface {
            ++$this->exchanges;
            if ($this->refuse) {
                return new MockResponse('{"message":"Bad credentials"}', ['http_code' => 401]);
            }

            return new MockResponse(json_encode(['token' => 'value-'.$this->exchanges, 'expires_at' => $this->clock->now()->modify($this->lifetime)->format(DATE_ATOM)], JSON_THROW_ON_ERROR), ['http_code' => 201]);
        });
        $jwt = new AppJwt('12345', AppKey::generate()->private, $this->clock);
        $this->tokens = new InstallationTokens(new InstallationTokenMinter($jwt, $http, new NullLogger()), '678', $this->clock);
    }
}
