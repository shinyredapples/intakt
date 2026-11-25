<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(AppJwt::class)]
#[UsesClass(InstallationTokenNotMinted::class)]
final class AppJwtTest extends TestCase
{
    public function testTheJwtIsSignedWithRs256ForTheAppAndLivesTenMinutesAtMost(): void
    {
        $key = AppKey::generate();
        $clock = new MockClock('2026-11-25 10:00:00 UTC');

        [$header, $claims, $signature] = explode('.', new AppJwt('12345', $key->private, $clock)->sign());

        self::assertSame(['alg' => 'RS256', 'typ' => 'JWT'], $this->decode($header));
        $decoded = $this->decode($claims);
        self::assertSame('12345', $decoded['iss']);
        self::assertIsInt($decoded['exp']);
        self::assertIsInt($decoded['iat']);
        self::assertLessThanOrEqual(600, $decoded['exp'] - $clock->now()->getTimestamp());
        self::assertLessThan($clock->now()->getTimestamp(), $decoded['iat']);
        self::assertSame(1, openssl_verify($header.'.'.$claims, $this->bytes($signature), $key->public, OPENSSL_ALGO_SHA256));
    }

    public function testAKeyThatCannotSignFailsWithoutNamingTheKey(): void
    {
        $unreadable = 'not a key '.AppKey::generate()->body();
        $jwt = new AppJwt('12345', $unreadable, new MockClock());

        try {
            (void) $jwt->sign();
            self::fail('An unreadable key must not sign.');
        } catch (InstallationTokenNotMinted $failure) {
            self::assertStringNotContainsString($unreadable, $failure->getMessage());
        }
    }

    public function testAKeyThatIsNotRsaDoesNotSign(): void
    {
        $jwt = new AppJwt('12345', AppKey::ellipticCurvePem(), new MockClock());

        $this->expectException(InstallationTokenNotMinted::class);

        (void) $jwt->sign();
    }

    /**
     * @return array<mixed>
     */
    private function decode(string $segment): array
    {
        $decoded = json_decode($this->bytes($segment), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function bytes(string $segment): string
    {
        return (string) base64_decode(strtr($segment, '-_', '+/'), true);
    }
}
