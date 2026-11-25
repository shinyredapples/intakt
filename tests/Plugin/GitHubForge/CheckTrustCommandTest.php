<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Domain\Installation;
use Intakt\Plugin\GitHubForge\Domain\SteeringTrust;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\CheckedInstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\RateLimitReadings;
use Intakt\Plugin\GitHubForge\Infrastructure\SteeringIdentities;
use Intakt\Plugin\GitHubForge\Infrastructure\SteeringTrustCheck;
use Intakt\Plugin\GitHubForge\Infrastructure\TrustAnswers;
use Intakt\Plugin\GitHubForge\UseCase\CheckTrust\CheckTrustCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(CheckTrustCommand::class)]
#[UsesClass(SteeringTrustCheck::class)]
#[UsesClass(SteeringIdentities::class)]
#[UsesClass(TrustAnswers::class)]
#[UsesClass(SteeringTrust::class)]
#[UsesClass(TrustNotChecked::class)]
#[UsesClass(AppJwt::class)]
#[UsesClass(CheckedInstallationTokens::class)]
#[UsesClass(ExpectedInstallation::class)]
#[UsesClass(Installation::class)]
#[UsesClass(InstallationNotReadBack::class)]
#[UsesClass(InstallationReadBack::class)]
#[UsesClass(InstallationReader::class)]
#[UsesClass(GitHubCalls::class)]
#[UsesClass(RateLimitReadings::class)]
#[UsesClass(InstallationToken::class)]
#[UsesClass(InstallationTokenMinter::class)]
#[UsesClass(InstallationTokenNotMinted::class)]
#[UsesClass(InstallationTokens::class)]
final class CheckTrustCommandTest extends TestCase
{
    public function testAWriterIsShownAsSteering(): void
    {
        $tester = $this->execute(201, RecordedTrust::WRITER);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("alice on acme/widget: writer, steers\n", $tester->getDisplay());
    }

    public function testAForeignBotIsShownAsNotSteering(): void
    {
        $tester = $this->execute(201, RecordedTrust::FOREIGN_BOT);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("dependabot[bot] on acme/widget: foreign-bot, does not steer\n", $tester->getDisplay());
    }

    public function testAFailedTokenExchangeExitsNonZeroWithTheReason(): void
    {
        $tester = $this->execute(401, RecordedTrust::WRITER);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('status 401', $tester->getDisplay());
    }

    private function execute(int $tokenStatus, string $account): CommandTester
    {
        $clock = new MockClock('2026-11-25 10:00:00 UTC');
        $http = new RecordedTrust($clock, $tokenStatus)->client();
        $jwt = new AppJwt('12345', AppKey::generate()->private, $clock);
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, $http, new NullLogger()), '678', $clock);
        $expected = ExpectedInstallation::fromSettings('contents:write,pull_requests:write,workflows:write', 'acme/widget,acme/gadget');
        $calls = new GitHubCalls($http, FreshReadings::at($clock), $tokens);
        $checked = new CheckedInstallationTokens(new InstallationReadBack(new InstallationReader($jwt, $tokens, $calls), $expected, new NullLogger()), $tokens);
        $answers = new TrustAnswers($calls);

        $application = new Application();
        $application->addCommand(new CheckTrustCommand(new SteeringTrustCheck(new SteeringIdentities($answers, $jwt, RecordedTrust::MAINTAINER_SLUG), $answers, $checked)));
        $tester = new CommandTester($application->find('intakt:forge:trust'));
        $tester->execute(['repository' => 'acme/widget', 'account' => $account]);

        return $tester;
    }
}
