<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\GitHubForge\Contract\ForgeConnectionVerdict;
use Intakt\Plugin\GitHubForge\Contract\InstallationDoesNotMatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Domain\Installation;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\DeadlineHttpClient;
use Intakt\Plugin\GitHubForge\Infrastructure\ForgeConnectionCheck;
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
#[CoversClass(DeadlineHttpClient::class)]
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
    private int $tokenStatus = 201;
    private bool $unreachable = false;
    private string $appId = '12345';
    private string $installationId = '678';
    private ?string $privateKey = null;
    private string $repositories = self::REPOSITORIES;
    private ?ExpectedInstallation $expected = null;
    private ?MockHttpClient $http = null;

    public function testAnInstallationThatMatchesTheConfigurationPasses(): void
    {
        $verdict = $this->check()->run();

        self::assertTrue($verdict->passes());
        self::assertSame(3, $this->requests);
    }

    public function testAnInstallationWhoseRepositoriesDifferFromTheConfigurationFailsWithTheMismatchFix(): void
    {
        $this->repositories = 'acme/widget';
        $verdict = $this->check()->run();

        self::assertFalse($verdict->passes());
        self::assertStringStartsWith('the installation does not match the configuration (', (string) $verdict->problem);
        self::assertStringContainsString('acme/gadget', (string) $verdict->problem);
        self::assertStringContainsString('GITHUB_APP_REPOSITORIES', (string) $verdict->fix);
    }

    public function testAPrivateKeyThatCannotSignFailsWithTheLocalKeyFixWithoutReachingGitHub(): void
    {
        $this->privateKey = 'not-a-key';
        $verdict = $this->check()->run();

        self::assertSame('the private key cannot sign (it cannot be read or is not an RSA key)', $verdict->problem);
        self::assertSame('set GITHUB_APP_PRIVATE_KEY to the PEM private key of the App', $verdict->fix);
        self::assertSame(0, $this->requests);
    }

    public function testSettingsThatCannotBeReadFailWithOneLineAndNoRequest(): void
    {
        $this->expected = $this->unreadableSettings();
        $verdict = $this->check()->run();

        self::assertSame('the App settings cannot be read (GITHUB_APP_PERMISSIONS must list name:level pairs, such as contents:write.)', $verdict->problem);
        self::assertStringContainsString('GITHUB_APP_PERMISSIONS', (string) $verdict->fix);
        self::assertSame(0, $this->requests);
    }

    public function testAnEmptyAppIdWithTheInstallationIdStillSetFails(): void
    {
        $this->appId = '';
        $this->privateKey = '';
        $verdict = $this->check()->run();

        self::assertSame('GITHUB_APP_ID is empty while GITHUB_APP_INSTALLATION_ID or GITHUB_APP_PRIVATE_KEY is still set', $verdict->problem);
        self::assertSame(0, $this->requests);
    }

    public function testAnEmptyAppIdWithThePrivateKeyStillSetFails(): void
    {
        $this->appId = '';
        $this->installationId = '';
        $verdict = $this->check()->run();

        self::assertFalse($verdict->passes());
        self::assertStringStartsWith('GITHUB_APP_ID is empty', (string) $verdict->problem);
    }

    public function testAnInstallationIdThatIsEmptyWhileTheAppIdIsSetFails(): void
    {
        $this->installationId = '';
        $verdict = $this->check()->run();

        self::assertSame('GITHUB_APP_INSTALLATION_ID is empty', $verdict->problem);
    }

    public function testNoAppAtAllPassesWithoutARequest(): void
    {
        $this->appId = '';
        $this->installationId = '';
        $this->privateKey = '';
        $verdict = $this->check()->run();

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

    public function testAPrivateKeyThatIsNotRsaFailsWithTheLocalKeyFixWithoutReachingGitHub(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $pem);
        $this->privateKey = (string) $pem;

        $verdict = $this->check()->run();

        self::assertSame('set GITHUB_APP_PRIVATE_KEY to the PEM private key of the App', $verdict->fix);
        self::assertSame(0, $this->requests);
    }

    public function testAnAppIdThatIsNotANumberFailsWithOneVerdictAndNoRequest(): void
    {
        $this->appId = "invalid\xFF";

        $verdict = $this->check()->run();

        self::assertSame('GITHUB_APP_ID is not a number', $verdict->problem);
        self::assertSame(0, $this->requests);
    }

    public function testAnAppIdThatIsANumberWithSurroundingSpacesIsAccepted(): void
    {
        $this->appId = ' 12345 ';

        self::assertTrue($this->check()->run()->passes());
    }

    public function testATokenExchangeThatGitHubRefusesFailsAsNotRun(): void
    {
        $this->tokenStatus = 404;

        $verdict = $this->check()->run();

        self::assertStringStartsWith('cannot run (', (string) $verdict->problem);
    }

    public function testRequestsThatTogetherOutlastTheDeadlineFailAsNotRunWithinIt(): void
    {
        $start = $this->clock->now();
        $this->http = $this->slowPagedGitHub();
        $this->repositories = 'acme/one,acme/two';

        $verdict = $this->check()->run();

        self::assertStringStartsWith('cannot run (', (string) $verdict->problem);
        self::assertLessThanOrEqual(15, $this->clock->now()->getTimestamp() - $start->getTimestamp());
    }

    public function testRequestsThatEndWithinTheDeadlinePass(): void
    {
        $this->http = $this->slowPagedGitHub(3);
        $this->repositories = 'acme/one,acme/two';

        self::assertTrue($this->check()->run()->passes());
    }

    public function testARateLimitedGitHubFailsAsNotRun(): void
    {
        $limited = new MockHttpClient(static fn (): ResponseInterface => new MockResponse('{}', ['http_code' => 429, 'response_headers' => ['retry-after: 30']]));

        $this->http = $limited;
        $verdict = $this->check()->run();

        self::assertFalse($verdict->passes());
        self::assertStringStartsWith('cannot run (', (string) $verdict->problem);
    }

    protected function setUp(): void
    {
        $this->key = AppKey::generate();
        $this->clock = new MockClock('2026-11-25 10:00:00 UTC');
    }

    private function check(): ForgeConnectionCheck
    {
        $privateKey = $this->privateKey ?? $this->key->private;

        return new ForgeConnectionCheck(
            $this->http ?? $this->github(),
            new AppJwt($this->appId, $privateKey, $this->clock),
            $this->expected ?? ExpectedInstallation::fromSettings(self::PERMISSIONS, $this->repositories),
            $this->clock,
            new RecordingLogger(),
            $this->appId,
            $this->installationId,
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
                return new MockResponse('{"token":"minted-value","expires_at":"2026-11-25T11:00:00Z"}', ['http_code' => $this->tokenStatus]);
            }
            $fixture = str_contains($url, '/installation/repositories') ? 'installation_repositories.json' : 'installation.json';

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture), ['http_code' => str_contains($url, '/installation/repositories') ? 200 : $this->installationStatus]);
        });
    }

    // Each request takes the given seconds on the clock and is cut off when its time limit is shorter, as a real transfer would be.
    private function slowPagedGitHub(int $seconds = 4): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options) use ($seconds): ResponseInterface {
            ++$this->requests;
            if ($options['max_duration'] < $seconds) {
                throw new TransportException('deadline exceeded');
            }
            $this->clock->sleep($seconds);
            if (str_ends_with($url, '/access_tokens')) {
                return new MockResponse('{"token":"minted-value","expires_at":"2026-11-25T11:00:00Z"}', ['http_code' => 201]);
            }
            if (str_contains($url, '/installation/repositories')) {
                $name = str_ends_with($url, '&page=1') ? 'acme/one' : 'acme/two';

                return new MockResponse((string) json_encode(['total_count' => 2, 'repositories' => [['full_name' => $name]]]));
            }

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/installation.json'));
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
