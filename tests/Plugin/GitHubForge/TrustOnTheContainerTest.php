<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\UseCase\CheckTrust\CheckTrustCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

// The command as the container wires it, so the settings and the scoped client are proven together, with GitHub's recorded answers.
#[CoversClass(CheckTrustCommand::class)]
final class TrustOnTheContainerTest extends KernelTestCase
{
    private const array SETTINGS = [
        'GITHUB_APP_ID' => '12345',
        'GITHUB_APP_INSTALLATION_ID' => '678',
        'GITHUB_APP_PERMISSIONS' => 'contents:write,pull_requests:write,workflows:write',
        'GITHUB_APP_REPOSITORIES' => 'acme/widget,acme/gadget',
        'GITHUB_MAINTAINER_APP_SLUG' => RecordedTrust::MAINTAINER_SLUG,
    ];

    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach ([...self::SETTINGS, 'GITHUB_APP_PRIVATE_KEY' => AppKey::generate()->private] as $name => $value) {
            $this->saved[$name] = $_SERVER[$name] ?? null;
            $_SERVER[$name] = $_ENV[$name] = $value;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            $_SERVER[$name] = $_ENV[$name] = $value;
        }
        parent::tearDown();
    }

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
        $github = new RecordedTrust()->client();
        // Both clients, so no request leaves the test: the token door uses the default client, the trust reads the scoped one.
        self::getContainer()->set('http_client', $github);
        self::getContainer()->set('github.client', $github);
        $tester = new CommandTester(new Application($kernel)->find('intakt:forge:trust'));

        $tester->execute(['repository' => 'acme/widget', 'account' => $account]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame($decision, trim($tester->getDisplay()));
    }
}
