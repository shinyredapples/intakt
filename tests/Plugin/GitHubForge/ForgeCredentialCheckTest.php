<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Core\Doctor\Contract\CheckResult;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\GitHubForge\Contract\InstallationDoesNotMatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Domain\Installation;
use Intakt\Plugin\GitHubForge\Domain\RateLimitReading;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\DeadlineHttpClient;
use Intakt\Plugin\GitHubForge\Infrastructure\ForgeCredentialCheck;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\RateLimitReadings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(ForgeCredentialCheck::class)]
#[UsesClass(CheckResult::class)]
#[UsesClass(DeadlineHttpClient::class)]
#[UsesClass(InstallationReadBack::class)]
#[UsesClass(InstallationReader::class)]
#[UsesClass(ExpectedInstallation::class)]
#[UsesClass(Installation::class)]
#[UsesClass(InstallationDoesNotMatchConfiguration::class)]
#[UsesClass(InstallationNotReadBack::class)]
#[UsesClass(InstallationToken::class)]
#[UsesClass(InstallationTokenNotMinted::class)]
#[UsesClass(InstallationTokenMinter::class)]
#[UsesClass(InstallationTokens::class)]
#[UsesClass(AppJwt::class)]
#[UsesClass(GitHubCalls::class)]
#[UsesClass(RateLimitReadings::class)]
#[UsesClass(RateLimitReading::class)]
#[UsesClass(ForgeRateLimited::class)]
final class ForgeCredentialCheckTest extends TestCase
{
    private const string PERMISSIONS = 'contents:write,pull_requests:write,workflows:write';
    private const string REPOSITORIES = 'acme/widget,acme/gadget';

    private MockClock $clock;
    private RateLimitReadings $shared;
    private int $requests = 0;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-25 10:00:00 UTC');
        $this->shared = FreshReadings::at($this->clock);
    }

    public function testTheForgeCredentialCheckPassesWhenTheInstallationReadsBack(): void
    {
        $result = $this->check(self::healthy(...))->run();

        self::assertTrue($result->passed);
    }

    public function testTheForgeCredentialCheckFailsWithItsFixTextOnARejectedCredential(): void
    {
        $result = $this->check(static fn (string $url): ResponseInterface => str_ends_with($url, '/access_tokens') ? new MockResponse('{}', ['http_code' => 401]) : self::healthy($url))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('status 401', $result->fix);
        self::assertStringContainsString('Check the GitHub App id, private key and installation id', $result->fix);
    }

    public function testTheForgeCredentialCheckFailsWithItsFixTextOnAnUnreadablePrivateKey(): void
    {
        $result = $this->check(self::healthy(...), jwt: new AppJwt('12345', 'not-a-key', $this->clock))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('private key cannot be read', $result->fix);
        self::assertSame(0, $this->requests);
    }

    public function testTheForgeCredentialCheckFailsWhenTheForgeReadTimesOut(): void
    {
        $result = $this->check(static fn (string $url): ResponseInterface => str_ends_with($url, '/access_tokens') ? self::healthy($url) : throw new TimeoutException('timed out'))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('did not complete: '.TimeoutException::class, $result->fix);
    }

    public function testTheForgeCredentialCheckFailsWhenTheInstallationDoesNotMatchTheConfiguration(): void
    {
        $result = $this->check(self::healthy(...), ExpectedInstallation::fromSettings('contents:read', self::REPOSITORIES))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('permission contents is expected at read but granted at write', $result->fix);
    }

    public function testTheForgeCredentialCheckNamesTheSettingsWhenTheExpectedInstallationCannotBeParsed(): void
    {
        $unparsable = new ReflectionClass(ExpectedInstallation::class)->newLazyProxy(static fn (): ExpectedInstallation => ExpectedInstallation::fromSettings('contents', self::REPOSITORIES));

        $result = $this->check(self::healthy(...), $unparsable)->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('Fix GITHUB_APP_PERMISSIONS and GITHUB_APP_REPOSITORIES', $result->fix);
    }

    public function testTheForgeCredentialCheckPassesWithoutReadingWhenNoAppIsConfigured(): void
    {
        $result = $this->check(self::healthy(...), settings: ['', '', ''])->run();

        self::assertTrue($result->passed);
        self::assertSame(0, $this->requests);
    }

    public function testTheForgeCredentialCheckFailsWhenTheAppIdIsEmptyButTheOtherAppSettingsAreSet(): void
    {
        $result = $this->check(self::healthy(...), settings: ['', '678', 'a-key'])->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('GITHUB_APP_ID is empty while GITHUB_APP_INSTALLATION_ID or GITHUB_APP_PRIVATE_KEY is set', $result->fix);
        self::assertSame(0, $this->requests);
    }

    public function testTheForgeCredentialCheckLeavesTheSharedBudgetReadingUntouched(): void
    {
        $result = $this->check(static fn (string $url): ResponseInterface => self::healthy($url, ['x-ratelimit-remaining: 4000', 'x-ratelimit-limit: 5000', 'x-ratelimit-reset: 1795600000']))->run();

        self::assertTrue($result->passed);
        self::assertNull($this->shared->latest('678'));
    }

    public function testTheForgeCredentialCheckLeavesNoPauseBehindWhenGitHubRateLimitsItsRead(): void
    {
        $result = $this->check(static fn (string $url): ResponseInterface => str_ends_with($url, '/access_tokens') ? self::healthy($url) : new MockResponse('{}', ['http_code' => 429, 'response_headers' => ['retry-after: 600']]))->run();

        self::assertFalse($result->passed);
        $this->shared->refuseWhileLimited('678', RateLimitReadings::CORE);
        $this->addToAssertionCount(1);
    }

    public function testTheForgeCredentialCheckHonorsTheWorkersPauseWithoutCallingGitHub(): void
    {
        try {
            $this->shared->observe('678', 429, ['retry-after' => ['600']]);
        } catch (ForgeRateLimited) {
        }

        $result = $this->check(self::healthy(...))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('GitHub rate-limits this installation until 2026-11-25T10:10:00+00:00', $result->fix);
        self::assertSame(0, $this->requests);
    }

    public function testTheForgeCredentialCheckStopsAtItsDeadlineWhenEachRequestIsSlow(): void
    {
        $result = $this->check(self::healthy(...), secondsPerRequest: 14)->run();

        self::assertFalse($result->passed);
        self::assertSame(1, $this->requests);
        self::assertSame('2026-11-25 10:00:14', $this->clock->now()->format('Y-m-d H:i:s'));
    }

    public function testTheForgeCredentialCheckPassesWhenItsRequestsFitTheDeadline(): void
    {
        $result = $this->check(self::healthy(...), secondsPerRequest: 4)->run();

        self::assertTrue($result->passed);
        self::assertSame(3, $this->requests);
    }

    /**
     * @param list<string> $headers
     */
    private static function healthy(string $url, array $headers = []): ResponseInterface
    {
        if (str_ends_with($url, '/access_tokens')) {
            return new MockResponse('{"token":"minted-value","expires_at":"2026-11-25T11:00:00Z"}', ['http_code' => 201]);
        }
        $fixture = str_contains($url, '/installation/repositories') ? 'installation_repositories.json' : 'installation.json';

        return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture), ['response_headers' => $headers]);
    }

    /**
     * @param callable(string): ResponseInterface $answer
     * @param array{string, string, string}       $settings
     */
    private function check(callable $answer, ?ExpectedInstallation $expected = null, array $settings = ['12345', '678', 'a-key'], ?AppJwt $jwt = null, int $secondsPerRequest = 0): ForgeCredentialCheck
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($answer, $secondsPerRequest): ResponseInterface {
            ++$this->requests;
            $this->clock->sleep($secondsPerRequest);

            return $answer($url);
        });

        return new ForgeCredentialCheck(
            $jwt ?? new AppJwt('12345', AppKey::generate()->private, $this->clock),
            $http,
            $this->shared,
            $expected ?? ExpectedInstallation::fromSettings(self::PERMISSIONS, self::REPOSITORIES),
            $this->clock,
            ...$settings,
        );
    }
}
