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
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\RateLimitReadings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

#[CoversClass(CheckedInstallationTokens::class)]
#[UsesClass(InstallationReadBack::class)]
#[UsesClass(InstallationReader::class)]
#[UsesClass(GitHubCalls::class)]
#[UsesClass(RateLimitReadings::class)]
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

    private RecordedInstallation $github;

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

        self::assertSame(1, $this->readBacks());
    }

    public function testAMismatchIsReadBackAgainOnTheNextCall(): void
    {
        $tokens = $this->tokens('contents:read');

        self::assertFalse($this->handsOut($tokens));
        self::assertFalse($this->handsOut($tokens));
        self::assertSame(2, $this->readBacks());
    }

    public function testTheInstallationIsReadBackAgainAfterAReset(): void
    {
        $tokens = $this->tokens(self::MATCHING);
        (void) $tokens->current();

        $tokens->reset();
        (void) $tokens->current();

        self::assertSame(2, $this->readBacks());
    }

    protected function setUp(): void
    {
        $this->github = new RecordedInstallation();
    }

    private function tokens(string $permissions): CheckedInstallationTokens
    {
        $clock = new MockClock('2026-11-25 10:00:00 UTC');
        $http = $this->github->client();
        $jwt = new AppJwt('12345', AppKey::generate()->private, $clock);
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, $http, new NullLogger()), '678', $clock);
        $readBack = new InstallationReadBack(new InstallationReader($jwt, $tokens, new GitHubCalls($http, FreshReadings::at($clock), $tokens)), ExpectedInstallation::fromSettings($permissions, 'acme/widget,acme/gadget'), new NullLogger());

        return new CheckedInstallationTokens($readBack, $tokens);
    }

    private function readBacks(): int
    {
        return count(array_filter($this->github->requested, static fn (string $url): bool => str_contains($url, '/installation/repositories')));
    }

    private function handsOut(CheckedInstallationTokens $tokens): bool
    {
        try {
            return '' !== $tokens->current()->value;
        } catch (InstallationDoesNotMatchConfiguration) {
            return false;
        }
    }
}
