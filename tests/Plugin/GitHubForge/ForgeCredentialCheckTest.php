<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Core\Doctor\Contract\CheckResult;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\GitHubForge\Contract\InstallationDoesNotMatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Domain\Installation;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\ForgeCredentialCheck;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubAppSetting;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\RateLimitReadings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(ForgeCredentialCheck::class)]
#[UsesClass(CheckResult::class)]
#[UsesClass(GitHubAppSetting::class)]
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
#[UsesClass(GitHubCalls::class)]
#[UsesClass(RateLimitReadings::class)]
#[UsesClass(ForgeRateLimited::class)]
final class ForgeCredentialCheckTest extends TestCase
{
    private const string PERMISSIONS = 'contents:write,pull_requests:write,workflows:write';
    private const string REPOSITORIES = 'acme/widget,acme/gadget';

    public function testTheForgeCredentialCheckPassesWhenTheInstallationReadsBack(): void
    {
        $result = $this->check(static fn (string $url): ResponseInterface => self::healthy($url))->run();

        self::assertTrue($result->passed);
    }

    public function testTheForgeCredentialCheckFailsWithItsFixTextOnARejectedCredential(): void
    {
        $result = $this->check(static fn (string $url): ResponseInterface => str_ends_with($url, '/access_tokens') ? new MockResponse('{}', ['http_code' => 401]) : self::healthy($url))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('status 401', $result->fix);
        self::assertStringContainsString('Check the GitHub App id, private key and installation id', $result->fix);
    }

    public function testTheForgeCredentialCheckFailsAsCouldNotRunWhenTheForgeReadTimesOut(): void
    {
        $result = $this->check(static fn (string $url): ResponseInterface => str_ends_with($url, '/access_tokens') ? self::healthy($url) : throw new TimeoutException('timed out'))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('did not complete: '.TimeoutException::class, $result->fix);
    }

    public function testTheForgeCredentialCheckFailsWhenTheInstallationDoesNotMatchTheConfiguration(): void
    {
        $result = $this->check(static fn (string $url): ResponseInterface => self::healthy($url), 'contents:read')->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('permission contents is expected at read but granted at write', $result->fix);
    }

    public function testTheForgeCredentialCheckPassesWithoutReadingWhenNoAppIsConfigured(): void
    {
        $result = $this->check(static fn (string $url): ResponseInterface => throw new TimeoutException('never read'), self::PERMISSIONS, '')->run();

        self::assertTrue($result->passed);
    }

    private static function healthy(string $url): ResponseInterface
    {
        if (str_ends_with($url, '/access_tokens')) {
            return new MockResponse('{"token":"minted-value","expires_at":"2026-11-25T11:00:00Z"}', ['http_code' => 201]);
        }
        $fixture = str_contains($url, '/installation/repositories') ? 'installation_repositories.json' : 'installation.json';

        return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture));
    }

    /**
     * @param callable(string): ResponseInterface $answer
     */
    private function check(callable $answer, string $permissions = self::PERMISSIONS, string $appId = '12345'): ForgeCredentialCheck
    {
        $clock = new MockClock('2026-11-25 10:00:00 UTC');
        $logger = new RecordingLogger();
        $http = new MockHttpClient(static fn (string $method, string $url): ResponseInterface => $answer($url));
        $jwt = new AppJwt('12345', AppKey::generate()->private, $clock);
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, $http, $logger), '678', $clock);
        $reader = new InstallationReader($jwt, $tokens, new GitHubCalls($http, FreshReadings::at($clock), $tokens));

        return new ForgeCredentialCheck(new InstallationReadBack($reader, ExpectedInstallation::fromSettings($permissions, self::REPOSITORIES), $logger), new GitHubAppSetting($appId, $logger));
    }
}
