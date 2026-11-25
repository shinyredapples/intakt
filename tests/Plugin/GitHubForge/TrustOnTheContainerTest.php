<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Domain\Installation;
use Intakt\Plugin\GitHubForge\Domain\SteeringTrust;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\CheckedInstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\SteeringIdentities;
use Intakt\Plugin\GitHubForge\Infrastructure\SteeringTrustCheck;
use Intakt\Plugin\GitHubForge\Infrastructure\TrustAnswers;
use Intakt\Plugin\GitHubForge\UseCase\CheckTrust\CheckTrustCommand;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

// The command as the container wires it, so the settings and the scoped client are proven together, with GitHub's recorded answers.
#[CoversClass(CheckTrustCommand::class)]
#[UsesClass(SteeringTrustCheck::class)]
#[UsesClass(SteeringIdentities::class)]
#[UsesClass(TrustAnswers::class)]
#[UsesClass(SteeringTrust::class)]
#[UsesClass(AppJwt::class)]
#[UsesClass(CheckedInstallationTokens::class)]
#[UsesClass(ExpectedInstallation::class)]
#[UsesClass(Installation::class)]
#[UsesClass(InstallationReadBack::class)]
#[UsesClass(InstallationReader::class)]
#[UsesClass(InstallationToken::class)]
#[UsesClass(InstallationTokenMinter::class)]
#[UsesClass(InstallationTokens::class)]
final class TrustOnTheContainerTest extends KernelTestCase
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
     * @return iterable<string, array{string, string}>
     */
    public static function accounts(): iterable
    {
        yield 'a writer' => [RecordedTrust::WRITER, 'alice on acme/widget: writer, steers'];
        yield 'a non-writer' => [RecordedTrust::TRIAGE, 'mallory on acme/widget: non-writer, does not steer'];
        yield 'a foreign bot' => [RecordedTrust::FOREIGN_BOT, 'dependabot[bot] on acme/widget: foreign-bot, does not steer'];
        yield "Intakt's own identity" => [RecordedTrust::OWN_IDENTITY, 'acme-intakt[bot] on acme/widget: own-identity, steers'];
        yield 'the maintainer' => [RecordedTrust::MAINTAINER, 'acme-intakt-maintainer[bot] on acme/widget: maintainer, steers'];
    }

    #[DataProvider('accounts')]
    public function testTheWiredCommandShowsTheTrustDecisionFromRecordedAnswers(string $account, string $decision): void
    {
        $kernel = self::bootKernel();
        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);
        $github = new RecordedTrust($clock)->client();
        // Both clients, so no request leaves the test: the token door uses the default client, the trust reads the scoped one.
        self::getContainer()->set('http_client', $github);
        self::getContainer()->set('github.client', $github);
        $tester = new CommandTester(new Application($kernel)->find('intakt:forge:trust'));

        $tester->execute(['repository' => 'acme/widget', 'account' => $account]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame($decision, trim($tester->getDisplay()));
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
