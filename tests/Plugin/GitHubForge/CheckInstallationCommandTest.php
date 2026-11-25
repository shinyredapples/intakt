<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Contract\InstallationDoesNotMatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Domain\Installation;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\RateLimitReadings;
use Intakt\Plugin\GitHubForge\UseCase\CheckInstallation\CheckInstallationCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(CheckInstallationCommand::class)]
#[UsesClass(InstallationReadBack::class)]
#[UsesClass(InstallationReader::class)]
#[UsesClass(GitHubCalls::class)]
#[UsesClass(RateLimitReadings::class)]
#[UsesClass(ExpectedInstallation::class)]
#[UsesClass(Installation::class)]
#[UsesClass(InstallationDoesNotMatchConfiguration::class)]
#[UsesClass(InstallationToken::class)]
#[UsesClass(InstallationTokenMinter::class)]
#[UsesClass(InstallationTokens::class)]
#[UsesClass(AppJwt::class)]
final class CheckInstallationCommandTest extends TestCase
{
    public function testAMatchingInstallationExitsZeroAndPrintsWhatWasReadBack(): void
    {
        $tester = $this->execute('contents:write,pull_requests:write,workflows:write');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('permission workflows:write', $tester->getDisplay());
        self::assertStringContainsString('repository acme/widget', $tester->getDisplay());
    }

    public function testAMismatchExitsNonZeroAndPrintsTheDifference(): void
    {
        $tester = $this->execute('contents:read,pull_requests:write,workflows:write');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('permission contents is expected at read but granted at write', $tester->getDisplay());
    }

    private function execute(string $permissions): CommandTester
    {
        $clock = new MockClock('2026-11-25 10:00:00 UTC');
        $http = new RecordedInstallation($clock)->client();
        $jwt = new AppJwt('12345', AppKey::generate()->private, $clock);
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, $http, new NullLogger()), '678', $clock);
        $readBack = new InstallationReadBack(new InstallationReader($jwt, $tokens, new GitHubCalls($http, FreshReadings::at($clock), $tokens)), ExpectedInstallation::fromSettings($permissions, 'acme/widget,acme/gadget'), new NullLogger());

        $application = new Application();
        $application->addCommand(new CheckInstallationCommand($readBack));
        $tester = new CommandTester($application->find('intakt:forge:installation'));
        $tester->execute([]);

        return $tester;
    }
}
