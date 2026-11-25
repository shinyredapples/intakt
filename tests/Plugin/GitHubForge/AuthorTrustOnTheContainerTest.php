<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Domain\Installation;
use Intakt\Plugin\GitHubForge\Domain\SteeringTrust;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\CheckedInstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubAuthorTrust;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\PullRequestAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\SteeringIdentities;
use Intakt\Plugin\GitHubForge\Infrastructure\SteeringTrustCheck;
use Intakt\Plugin\GitHubForge\Infrastructure\TrustAnswers;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

// The query as the container wires it, so the settings and the scoped client are proven together, with GitHub's recorded answers.
#[CoversClass(GitHubAuthorTrust::class)]
#[UsesClass(AppJwt::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(CheckedInstallationTokens::class)]
#[UsesClass(ExpectedInstallation::class)]
#[UsesClass(Installation::class)]
#[UsesClass(InstallationReadBack::class)]
#[UsesClass(InstallationReader::class)]
#[UsesClass(InstallationToken::class)]
#[UsesClass(InstallationTokenMinter::class)]
#[UsesClass(InstallationTokens::class)]
#[UsesClass(PullRequestAddress::class)]
#[UsesClass(SteeringIdentities::class)]
#[UsesClass(SteeringTrust::class)]
#[UsesClass(SteeringTrustCheck::class)]
#[UsesClass(TrustAnswers::class)]
final class AuthorTrustOnTheContainerTest extends KernelTestCase
{
    private const array SETTINGS = [
        'GITHUB_APP_ID' => '12345',
        'GITHUB_APP_INSTALLATION_ID' => '678',
        'GITHUB_APP_PERMISSIONS' => 'contents:write,pull_requests:write,workflows:write',
        'GITHUB_APP_REPOSITORIES' => 'acme/widget,acme/gadget',
        'GITHUB_MAINTAINER_APP_SLUG' => RecordedTrust::MAINTAINER_SLUG,
    ];

    private const string PRIVATE_KEY = 'GITHUB_APP_PRIVATE_KEY';

    /** @var array<string, mixed> */
    private array $savedServer = [];

    /** @var array<string, mixed> */
    private array $savedEnv = [];

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function pullRequests(): iterable
    {
        yield 'a writer' => [RecordedTrust::PULL_BY_WRITER, true];
        yield 'a non-writer' => [RecordedTrust::PULL_BY_TRIAGE, false];
        yield 'a failing check' => [RecordedTrust::PULL_BY_STRANGER, false];
        yield "Intakt's own identity" => [RecordedTrust::PULL_BY_OWN_IDENTITY, true];
    }

    #[DataProvider('pullRequests')]
    public function testTheWiredQueryAnswersFromRecordedAnswers(string $pullRequest, bool $trusted): void
    {
        self::bootKernel();
        $github = RecordedTrust::onTheWallClock()->client();
        // Both clients, so no request leaves the test: the token door uses the default client, the trust reads the scoped one.
        self::getContainer()->set('http_client', $github);
        self::getContainer()->set('github.client', $github);
        $trust = self::getContainer()->get('intakt.test.author_trust');
        self::assertInstanceOf(GitHubAuthorTrust::class, $trust);

        self::assertSame($trusted, $trust->isAuthorTrusted(new ChangeId($pullRequest)));
    }

    protected function setUp(): void
    {
        $this->savedServer = [];
        $this->savedEnv = [];
        foreach ([...self::SETTINGS, self::PRIVATE_KEY => AppKey::generate()->private] as $name => $value) {
            if (array_key_exists($name, $_SERVER)) {
                $this->savedServer[$name] = $_SERVER[$name];
            }
            if (array_key_exists($name, $_ENV)) {
                $this->savedEnv[$name] = $_ENV[$name];
            }
            $_SERVER[$name] = $_ENV[$name] = $value;
        }
    }

    protected function tearDown(): void
    {
        foreach ([...array_keys(self::SETTINGS), self::PRIVATE_KEY] as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
        }
        $_SERVER = [...$_SERVER, ...$this->savedServer];
        $_ENV = [...$_ENV, ...$this->savedEnv];
        parent::tearDown();
    }
}
