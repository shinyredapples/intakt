<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\GitHubForge\Contract\ForgeConnectionCheck;
use Intakt\Plugin\GitHubForge\Contract\ForgeConnectionVerdict;
use Intakt\Plugin\GitHubForge\Contract\InstallationDoesNotMatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Domain\Installation;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\RateLimitReadings;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(ForgeConnectionCheck::class)]
#[CoversClass(ForgeConnectionVerdict::class)]
#[UsesClass(ExpectedInstallation::class)]
#[UsesClass(Installation::class)]
#[UsesClass(InstallationDoesNotMatchConfiguration::class)]
#[UsesClass(InstallationNotReadBack::class)]
#[UsesClass(InstallationToken::class)]
#[UsesClass(InstallationTokenNotMinted::class)]
#[UsesClass(InstallationTokenMinter::class)]
#[UsesClass(InstallationTokens::class)]
#[UsesClass(InstallationReadBack::class)]
#[UsesClass(InstallationReader::class)]
#[UsesClass(AppJwt::class)]
#[UsesClass(GitHubCalls::class)]
#[UsesClass(RateLimitReadings::class)]
#[UsesClass(ForgeRateLimited::class)]
final class ForgeConnectionCheckTest extends TestCase
{
    private const string PERMISSIONS = 'contents:write,pull_requests:write,workflows:write';
    private const string REPOSITORIES = 'acme/widget,acme/gadget';

    private AppKey $key;
    private MockClock $clock;
    private int $requests = 0;
    private int $installationStatus = 200;
    private bool $unreachable = false;

    protected function setUp(): void
    {
        $this->key = AppKey::generate();
        $this->clock = new MockClock('2026-11-25 10:00:00 UTC');
    }

    public function testAnInstallationThatMatchesTheConfigurationPasses(): void
    {
        $verdict = $this->check()->run();

        self::assertTrue($verdict->passes());
        self::assertSame(3, $this->requests);
    }

    public function testAnInstallationWhoseRepositoriesDifferFromTheConfigurationFailsWithTheMismatchFix(): void
    {
        $verdict = $this->check(repositories: 'acme/widget')->run();

        self::assertFalse($verdict->passes());
        self::assertStringStartsWith('the installation does not match the configuration (', (string) $verdict->problem);
        self::assertStringContainsString('acme/gadget', (string) $verdict->problem);
        self::assertStringContainsString('GITHUB_APP_REPOSITORIES', (string) $verdict->fix);
    }

    public function testAPrivateKeyThatCannotSignFailsWithTheLocalKeyFixWithoutReachingGitHub(): void
    {
        $verdict = $this->check(privateKey: 'not-a-key')->run();

        self::assertSame('the private key cannot sign (it cannot be read or is not an RSA key)', $verdict->problem);
        self::assertSame('set GITHUB_APP_PRIVATE_KEY to the PEM private key of the App', $verdict->fix);
        self::assertSame(0, $this->requests);
    }

    public function testSettingsThatCannotBeReadFailWithOneLineAndNoRequest(): void
    {
        $verdict = $this->check(expected: $this->unreadableSettings())->run();

        self::assertSame('the App settings cannot be read (GITHUB_APP_PERMISSIONS must list name:level pairs, such as contents:write.)', $verdict->problem);
        self::assertStringContainsString('GITHUB_APP_PERMISSIONS', (string) $verdict->fix);
        self::assertSame(0, $this->requests);
    }

    public function testAnEmptyAppIdWithTheInstallationIdStillSetFails(): void
    {
        $verdict = $this->check(appId: '', privateKey: '')->run();

        self::assertSame('GITHUB_APP_ID is empty while GITHUB_APP_INSTALLATION_ID or GITHUB_APP_PRIVATE_KEY is still set', $verdict->problem);
        self::assertSame(0, $this->requests);
    }

    public function testAnEmptyAppIdWithThePrivateKeyStillSetFails(): void
    {
        $verdict = $this->check(appId: '', installationId: '')->run();

        self::assertFalse($verdict->passes());
        self::assertStringStartsWith('GITHUB_APP_ID is empty', (string) $verdict->problem);
    }

    public function testAnInstallationIdThatIsEmptyWhileTheAppIdIsSetFails(): void
    {
        $verdict = $this->check(installationId: '')->run();

        self::assertSame('GITHUB_APP_INSTALLATION_ID is empty', $verdict->problem);
    }

    public function testNoAppAtAllPassesWithoutARequest(): void
    {
        $verdict = $this->check(appId: '', installationId: '', privateKey: '')->run();

        self::assertTrue($verdict->passes());
        self::assertSame(0, $this->requests);
    }

    public function testAGitHubThatRefusesTheReadBackFailsAsNotRun(): void
    {
        $this->installationStatus = 401;

        $verdict = $this->check()->run();

        self::assertSame('cannot run (GitHub refused to read the installation back with status 401.)', $verdict->problem);
        self::assertStringContainsString('GitHub is reachable', (string) $verdict->fix);
    }

    public function testAGitHubThatDoesNotAnswerFailsAsNotRun(): void
    {
        $this->unreachable = true;

        $verdict = $this->check()->run();

        self::assertFalse($verdict->passes());
        self::assertStringStartsWith('cannot run (', (string) $verdict->problem);
    }

    public function testARateLimitedGitHubFailsAsNotRun(): void
    {
        $limited = new MockHttpClient(static fn (): ResponseInterface => new MockResponse('{}', ['http_code' => 429, 'response_headers' => ['retry-after: 30']]));

        $verdict = $this->check(http: $limited)->run();

        self::assertFalse($verdict->passes());
        self::assertStringStartsWith('cannot run (', (string) $verdict->problem);
    }

    private function check(
        string $appId = '12345',
        string $installationId = '678',
        ?string $privateKey = null,
        string $repositories = self::REPOSITORIES,
        ?ExpectedInstallation $expected = null,
        ?MockHttpClient $http = null,
    ): ForgeConnectionCheck {
        $privateKey ??= $this->key->private;

        return new ForgeConnectionCheck(
            $http ?? $this->github(),
            new AppJwt($appId, $privateKey, $this->clock),
            $expected ?? ExpectedInstallation::fromSettings(self::PERMISSIONS, $repositories),
            $this->clock,
            new RecordingLogger(),
            $appId,
            $installationId,
            $privateKey,
        );
    }

    private function github(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url): ResponseInterface {
            ++$this->requests;
            if ($this->unreachable) {
                throw new TransportException('connection reset');
            }
            if (str_ends_with($url, '/access_tokens')) {
                return new MockResponse('{"token":"minted-value","expires_at":"2026-11-25T11:00:00Z"}', ['http_code' => 201]);
            }
            $fixture = str_contains($url, '/installation/repositories') ? 'installation_repositories.json' : 'installation.json';

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture), ['http_code' => str_contains($url, '/installation/repositories') ? 200 : $this->installationStatus]);
        });
    }

    private function unreadableSettings(): ExpectedInstallation
    {
        return new ReflectionClass(ExpectedInstallation::class)->newLazyGhost(static function (): never {
            ExpectedInstallation::fromSettings('contents', 'acme/widget');

            throw new InvalidArgumentException('unreachable');
        });
    }
}
