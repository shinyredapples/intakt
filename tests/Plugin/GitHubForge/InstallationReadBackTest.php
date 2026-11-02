<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Contract\InstallationDoesNotMatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Domain\Installation;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
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
#[CoversClass(ExpectedInstallation::class)]
#[UsesClass(Installation::class)]
#[UsesClass(InstallationDoesNotMatchConfiguration::class)]
#[UsesClass(InstallationNotReadBack::class)]
#[UsesClass(InstallationToken::class)]
#[UsesClass(InstallationTokenNotMinted::class)]
#[UsesClass(InstallationTokenMinter::class)]
#[UsesClass(InstallationTokens::class)]
#[UsesClass(AppJwt::class)]
final class InstallationReadBackTest extends TestCase
{
    private const string PERMISSIONS = 'contents:write,pull_requests:write,workflows:write';
    private const string REPOSITORIES = 'acme/widget,acme/gadget';

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
        $this->expectExceptionMessage('permission contents is expected at read but granted at write');

        (void) $readBack->check();
    }

    public function testAnExpectedPermissionTheInstallationLacksRefusesAndNamesIt(): void
    {
        $readBack = $this->readBack(self::PERMISSIONS.',issues:write', self::REPOSITORIES);

        $this->expectException(InstallationDoesNotMatchConfiguration::class);
        $this->expectExceptionMessage('permission issues is expected at write but not granted');

        (void) $readBack->check();
    }

    public function testAPermissionBeyondTheConfigurationRefusesAndNamesIt(): void
    {
        $readBack = $this->readBack('contents:write,pull_requests:write', self::REPOSITORIES);

        $this->expectException(InstallationDoesNotMatchConfiguration::class);
        $this->expectExceptionMessage('permission workflows is granted at write but not expected');

        (void) $readBack->check();
    }

    public function testARepositoryTheInstallationLacksRefusesAndNamesIt(): void
    {
        $readBack = $this->readBack(self::PERMISSIONS, self::REPOSITORIES.',acme/other');

        $this->expectException(InstallationDoesNotMatchConfiguration::class);
        $this->expectExceptionMessage('repository acme/other is expected but not in the installation');

        (void) $readBack->check();
    }

    public function testARepositoryBeyondTheConfigurationRefusesAndNamesIt(): void
    {
        $readBack = $this->readBack(self::PERMISSIONS, 'acme/widget');

        $this->expectException(InstallationDoesNotMatchConfiguration::class);
        $this->expectExceptionMessage('repository acme/gadget is in the installation but not expected');

        (void) $readBack->check();
    }

    public function testRepositoriesOnASecondPageAreReadBack(): void
    {
        $readBack = $this->readBack(self::PERMISSIONS, self::REPOSITORIES, pageFixtures: ['installation_pageFixturespage_1.json', 'installation_pageFixturespage_2.json']);

        self::assertSame(['acme/widget', 'acme/gadget'], $readBack->check()->repositories);
    }

    public function testARefusedReadFailsTheCheckWithTheStatus(): void
    {
        $readBack = $this->readBack(self::PERMISSIONS, self::REPOSITORIES, installationStatus: 401);

        $this->expectException(InstallationNotReadBack::class);
        $this->expectExceptionMessage('status 401');

        (void) $readBack->check();
    }

    public function testATransportErrorFailsTheCheck(): void
    {
        $readBack = $this->readBack(self::PERMISSIONS, self::REPOSITORIES, transportError: true);

        $this->expectException(InstallationNotReadBack::class);

        (void) $readBack->check();
    }

    public function testAFailedTokenExchangeFailsTheCheck(): void
    {
        $readBack = $this->readBack(self::PERMISSIONS, self::REPOSITORIES, tokenStatus: 401);

        $this->expectException(InstallationNotReadBack::class);
        $this->expectExceptionMessage('status 401');

        (void) $readBack->check();
    }

    public function testAnAnswerWithoutPermissionsFailsTheCheck(): void
    {
        $readBack = $this->readBack(self::PERMISSIONS, self::REPOSITORIES, installationBody: '{"id":678}');

        $this->expectException(InstallationNotReadBack::class);

        (void) $readBack->check();
    }

    public function testNeitherTheKeyNorTheTokenReachesTheLogsOrTheMessage(): void
    {
        $key = AppKey::generate();
        $logger = new RecordingLogger();
        $readBack = $this->readBack('contents:read', self::REPOSITORIES, key: $key, logger: $logger);

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

    /**
     * @param list<string> $pageFixtures fixtures answered in turn, one per page
     */
    private function readBack(
        string $permissions,
        string $repositories,
        array $pageFixtures = [],
        int $installationStatus = 200,
        bool $transportError = false,
        int $tokenStatus = 201,
        ?string $installationBody = null,
        ?AppKey $key = null,
        ?RecordingLogger $logger = null,
    ): InstallationReadBack {
        $pages = [] === $pageFixtures ? ['installation_repositories.json'] : $pageFixtures;
        $clock = new MockClock('2026-11-25 10:00:00 UTC');
        $http = new MockHttpClient(function (string $method, string $url) use (&$pages, $installationStatus, $transportError, $tokenStatus, $installationBody): ResponseInterface {
            if (str_ends_with($url, '/access_tokens')) {
                return new MockResponse('{"token":"minted-value","expires_at":"2026-11-25T11:00:00Z"}', ['http_code' => $tokenStatus]);
            }
            if ($transportError) {
                throw new TransportException('connection reset');
            }
            if (str_contains($url, '/installation/repositories')) {
                return new MockResponse(self::recorded((string) array_shift($pages)));
            }

            return new MockResponse($installationBody ?? self::recorded('installation.json'), ['http_code' => $installationStatus]);
        });
        $jwt = new AppJwt('12345', ($key ?? AppKey::generate())->private, $clock);
        $logger ??= new RecordingLogger();
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, $http, $logger), '678', $clock);

        return new InstallationReadBack($jwt, $tokens, $http, ExpectedInstallation::fromSettings($permissions, $repositories), '678', $logger);
    }

    private static function recorded(string $fixture): string
    {
        return (string) file_get_contents(__DIR__.'/Fixtures/'.$fixture);
    }
}
