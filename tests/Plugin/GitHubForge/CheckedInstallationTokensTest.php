<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Contract\InstallationDoesNotMatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Domain\Installation;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\CheckedInstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
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

#[CoversClass(CheckedInstallationTokens::class)]
#[UsesClass(InstallationReadBack::class)]
#[UsesClass(InstallationReader::class)]
#[UsesClass(ExpectedInstallation::class)]
#[UsesClass(Installation::class)]
#[UsesClass(InstallationDoesNotMatchConfiguration::class)]
#[UsesClass(InstallationNotReadBack::class)]
#[UsesClass(InstallationToken::class)]
#[UsesClass(InstallationTokenMinter::class)]
#[UsesClass(InstallationTokens::class)]
#[UsesClass(AppJwt::class)]
final class CheckedInstallationTokensTest extends TestCase
{
    private const string MATCHING = 'contents:write,pull_requests:write,workflows:write';

    /** @var list<string> */
    private array $requested = [];

    public function testAMatchingInstallationHandsOutTheToken(): void
    {
        self::assertSame('minted-value', $this->tokens(self::MATCHING)->current()->value);
    }

    public function testAMismatchHandsOutNoToken(): void
    {
        $tokens = $this->tokens('contents:read');

        $this->expectException(InstallationDoesNotMatchConfiguration::class);

        (void) $tokens->current();
    }

    public function testTheInstallationIsReadBackOnlyBeforeTheFirstToken(): void
    {
        $tokens = $this->tokens(self::MATCHING);

        (void) $tokens->current();
        (void) $tokens->current();

        self::assertCount(1, array_filter($this->requested, static fn (string $url): bool => str_contains($url, '/app/installations/678') && !str_ends_with($url, '/access_tokens')));
    }

    public function testAMismatchIsReadBackAgainOnTheNextCall(): void
    {
        $tokens = $this->tokens('contents:read');

        self::assertFalse(self::handsOut($tokens));
        self::assertFalse(self::handsOut($tokens));
        self::assertCount(2, array_filter($this->requested, static fn (string $url): bool => str_contains($url, '/installation/repositories')));
    }

    private function tokens(string $permissions): CheckedInstallationTokens
    {
        $clock = new MockClock('2026-11-25 10:00:00 UTC');
        $http = new MockHttpClient(function (string $method, string $url): ResponseInterface {
            $this->requested[] = $url;

            return new MockResponse(match (true) {
                str_ends_with($url, '/access_tokens') => '{"token":"minted-value","expires_at":"2026-11-25T11:00:00Z"}',
                str_contains($url, '/installation/repositories') => (string) file_get_contents(__DIR__.'/Fixtures/installation_repositories.json'),
                default => (string) file_get_contents(__DIR__.'/Fixtures/installation.json'),
            }, ['http_code' => str_ends_with($url, '/access_tokens') ? 201 : 200]);
        });
        $jwt = new AppJwt('12345', AppKey::generate()->private, $clock);
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, $http, new NullLogger()), '678', $clock);
        $readBack = new InstallationReadBack(new InstallationReader($jwt, $tokens, $http), ExpectedInstallation::fromSettings($permissions, 'acme/widget,acme/gadget'), new NullLogger());

        return new CheckedInstallationTokens($readBack, $tokens);
    }

    private static function handsOut(CheckedInstallationTokens $tokens): bool
    {
        try {
            return '' !== $tokens->current()->value;
        } catch (InstallationDoesNotMatchConfiguration) {
            return false;
        }
    }
}
