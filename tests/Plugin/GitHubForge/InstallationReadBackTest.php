<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
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
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(InstallationReadBack::class)]
#[CoversClass(InstallationReader::class)]
#[CoversClass(ExpectedInstallation::class)]
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
#[UsesClass(ForgeRateLimited::class)]
final class InstallationReadBackTest extends TestCase
{
    private const string PERMISSIONS = 'contents:write,pull_requests:write,workflows:write';
    private const string REPOSITORIES = 'acme/widget,acme/gadget';

    /** @var list<string> */
    private array $pages = ['installation_repositories.json'];
    private int $installationStatus = 200;
    private int $tokenStatus = 201;
    private bool $transportError = false;
    private ?string $installationBody = null;
    private AppKey $key;
    private RecordingLogger $logger;
    private MockClock $clock;
    private RateLimitReadings $readings;

    public function testAnInstallationThatMatchesTheConfigurationPasses(): void
    {
        $installation = $this->readBack(self::PERMISSIONS, self::REPOSITORIES)->check();

        self::assertSame('write', $installation->permissions['workflows']);
        self::assertSame(['acme/widget', 'acme/gadget'], $installation->repositories);
    }

    public function testAPermissionGrantedAtAnotherLevelRefusesAndNamesBothLevels(): void
    {
        $readBack = $this->readBack('contents:read,pull_requests:write,workflows:write', self::REPOSITORIES);

        $this->expectException(InstallationDoesNotMatchConfiguration::class);
        $this->expectExceptionMessageMatches('/permission\ contents\ is\ expected\ at\ read\ but\ granted\ at\ write/');

        (void) $readBack->check();
    }

    public function testAnExpectedPermissionTheInstallationLacksRefusesAndNamesIt(): void
    {
        $readBack = $this->readBack(self::PERMISSIONS.',issues:write', self::REPOSITORIES);

        $this->expectException(InstallationDoesNotMatchConfiguration::class);
        $this->expectExceptionMessageMatches('/permission\ issues\ is\ expected\ at\ write\ but\ not\ granted/');

        (void) $readBack->check();
    }

    public function testAPermissionBeyondTheConfigurationRefusesAndNamesIt(): void
    {
        $readBack = $this->readBack('contents:write,pull_requests:write', self::REPOSITORIES);

        $this->expectException(InstallationDoesNotMatchConfiguration::class);
        $this->expectExceptionMessageMatches('/permission\ workflows\ is\ granted\ at\ write\ but\ not\ expected/');

        (void) $readBack->check();
    }

    public function testARepositoryTheInstallationLacksRefusesAndNamesIt(): void
    {
        $readBack = $this->readBack(self::PERMISSIONS, self::REPOSITORIES.',acme/other');

        $this->expectException(InstallationDoesNotMatchConfiguration::class);
        $this->expectExceptionMessageMatches('/repository\ acme\/other\ is\ expected\ but\ not\ in\ the\ installation/');

        (void) $readBack->check();
    }

    public function testARepositoryBeyondTheConfigurationRefusesAndNamesIt(): void
    {
        $readBack = $this->readBack(self::PERMISSIONS, 'acme/widget');

        $this->expectException(InstallationDoesNotMatchConfiguration::class);
        $this->expectExceptionMessageMatches('/repository\ acme\/gadget\ is\ in\ the\ installation\ but\ not\ expected/');

        (void) $readBack->check();
    }

    public function testRepositoriesOnASecondPageAreReadBack(): void
    {
        $this->pages = ['installation_repositories_page_1.json', 'installation_repositories_page_2.json'];

        self::assertSame(['acme/widget', 'acme/gadget'], $this->readBack(self::PERMISSIONS, self::REPOSITORIES)->check()->repositories);
    }

    public function testAnEmptyPageBeforeEveryRepositoryWasListedFailsTheCheck(): void
    {
        $this->pages = ['installation_repositories_page_1.json', 'installation_repositories_empty_page.json'];
        $readBack = $this->readBack(self::PERMISSIONS, 'acme/widget');

        $this->expectException(InstallationNotReadBack::class);
        $this->expectExceptionMessageMatches('/listed\ 1\ repositories\ of\ the\ installation\ while\ it\ counts\ 2/');

        (void) $readBack->check();
    }

    public function testMoreRepositoriesThanTheTotalFailsTheCheck(): void
    {
        $this->pages = ['installation_repositories_page_1.json', 'installation_repositories.json'];
        $readBack = $this->readBack(self::PERMISSIONS, self::REPOSITORIES);

        $this->expectException(InstallationNotReadBack::class);

        (void) $readBack->check();
    }

    public function testARepositoryListedTwiceFailsTheCheck(): void
    {
        $this->pages = ['installation_repositories_page_1.json', 'installation_repositories_page_1.json'];
        $readBack = $this->readBack(self::PERMISSIONS, 'acme/widget');

        $this->expectException(InstallationNotReadBack::class);

        (void) $readBack->check();
    }

    public function testARefusedReadFailsTheCheckWithTheStatus(): void
    {
        $this->installationStatus = 401;
        $readBack = $this->readBack(self::PERMISSIONS, self::REPOSITORIES);

        $this->expectException(InstallationNotReadBack::class);
        $this->expectExceptionMessageMatches('/status\ 401/');

        (void) $readBack->check();
    }

    public function testATransportErrorFailsTheCheck(): void
    {
        $this->transportError = true;
        $readBack = $this->readBack(self::PERMISSIONS, self::REPOSITORIES);

        $this->expectException(InstallationNotReadBack::class);

        (void) $readBack->check();
    }

    public function testAFailedTokenExchangeFailsTheCheck(): void
    {
        $this->tokenStatus = 401;
        $readBack = $this->readBack(self::PERMISSIONS, self::REPOSITORIES);

        $this->expectException(InstallationNotReadBack::class);
        $this->expectExceptionMessageMatches('/status\ 401/');

        (void) $readBack->check();
    }

    public function testAnAnswerWithoutPermissionsFailsTheCheck(): void
    {
        $this->installationBody = '{"id":678}';
        $readBack = $this->readBack(self::PERMISSIONS, self::REPOSITORIES);

        $this->expectException(InstallationNotReadBack::class);

        (void) $readBack->check();
    }

    public function testNeitherTheKeyNorTheTokenReachesTheLogsOrTheMessage(): void
    {
        $key = $this->key;
        $logger = $this->logger;
        $readBack = $this->readBack('contents:read', self::REPOSITORIES);

        try {
            (void) $readBack->check();
            self::fail('A mismatch must refuse.');
        } catch (InstallationDoesNotMatchConfiguration $failure) {
            $everything = implode("\n", $logger->lines).$failure->getMessage();
        }

        self::assertNotSame([], $logger->lines);
        self::assertStringNotContainsString($key->body(), $everything);
        self::assertStringNotContainsString('minted-value', $everything);
    }

    public function testSettingsWithoutAnyEntryAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ExpectedInstallation::fromSettings('', '');
    }

    public function testAPermissionWithoutALevelIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ExpectedInstallation::fromSettings('contents', 'acme/widget');
    }

    public function testAPermissionNamedTwiceIsRefused(): void
    {
        try {
            ExpectedInstallation::fromSettings('contents:read,contents:write', 'acme/widget');
            self::fail('A permission named twice must be refused.');
        } catch (InvalidArgumentException $refused) {
            self::assertSame('GITHUB_APP_PERMISSIONS names contents more than once.', $refused->getMessage());
        }
    }

    public function testDistinctPermissionsAreAccepted(): void
    {
        $expected = ExpectedInstallation::fromSettings('contents:read,issues:write', 'acme/widget');

        self::assertSame(['contents' => 'read', 'issues' => 'write'], $expected->permissions);
    }

    public function testTheRepositoryListingWaitsForTheResetOfTheInstallation(): void
    {
        try {
            $this->readings->observe('678', 429, ['retry-after' => ['600']]);
        } catch (ForgeRateLimited) {
        }

        try {
            (void) $this->readBack(self::PERMISSIONS, self::REPOSITORIES)->check();
            self::fail('The listing was read while the installation waits for its reset.');
        } catch (ForgeRateLimited $limited) {
            self::assertEquals($this->clock->now()->modify('+600 seconds'), $limited->resetsAt);
        }

        self::assertSame(['installation_repositories.json'], $this->pages);
    }

    protected function setUp(): void
    {
        $this->key = AppKey::generate();
        $this->logger = new RecordingLogger();
        $this->clock = new MockClock('2026-11-25 10:00:00 UTC');
        $this->readings = FreshReadings::at($this->clock);
    }

    private function readBack(string $permissions, string $repositories): InstallationReadBack
    {
        $clock = $this->clock;
        $http = new MockHttpClient(function (string $method, string $url): ResponseInterface {
            if (str_ends_with($url, '/access_tokens')) {
                return new MockResponse('{"token":"minted-value","expires_at":"2026-11-25T11:00:00Z"}', ['http_code' => $this->tokenStatus]);
            }
            if ($this->transportError) {
                throw new TransportException('connection reset');
            }
            if (str_contains($url, '/installation/repositories')) {
                return new MockResponse($this->recorded((string) array_shift($this->pages)));
            }

            return new MockResponse($this->installationBody ?? $this->recorded('installation.json'), ['http_code' => $this->installationStatus]);
        });
        $jwt = new AppJwt('12345', $this->key->private, $clock);
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, $http, $this->logger), '678', $clock);

        return new InstallationReadBack(new InstallationReader($jwt, $tokens, new GitHubCalls($http, $this->readings, $tokens)), ExpectedInstallation::fromSettings($permissions, $repositories), $this->logger);
    }

    private function recorded(string $fixture): string
    {
        return (string) file_get_contents(__DIR__.'/Fixtures/'.$fixture);
    }
}
