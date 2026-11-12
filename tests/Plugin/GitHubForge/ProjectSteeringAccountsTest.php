<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
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
use Intakt\Plugin\GitHubForge\Infrastructure\ProjectSteeringAccounts;
use Intakt\Plugin\GitHubForge\Infrastructure\RateLimitReadings;
use Intakt\Plugin\GitHubForge\Infrastructure\RepositoryAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\SteeringIdentities;
use Intakt\Plugin\GitHubForge\Infrastructure\SteeringTrustCheck;
use Intakt\Plugin\GitHubForge\Infrastructure\TrustAnswers;
use Intakt\Plugin\GitHubForge\Infrastructure\WatchedRepositories;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

#[CoversClass(ProjectSteeringAccounts::class)]
#[UsesClass(RepositoryAddress::class)]
#[UsesClass(WatchedRepositories::class)]
#[UsesClass(SteeringTrustCheck::class)]
#[UsesClass(SteeringIdentities::class)]
#[UsesClass(TrustAnswers::class)]
#[UsesClass(SteeringTrust::class)]
#[UsesClass(TrustNotChecked::class)]
#[UsesClass(ForgeReadFailed::class)]
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
final class ProjectSteeringAccountsTest extends TestCase
{
    public function testAnAccountWithWriteAccessOnTheProjectsRepositoryCanSteer(): void
    {
        $github = new RecordedTrust();

        self::assertTrue($this->accounts($github)->steers('alpha', RecordedTrust::WRITER));
        self::assertContains('https://api.github.com/repos/acme/widget/collaborators/alice/permission', $github->requested);
    }

    public function testAnAccountWithoutWriteAccessCannotSteer(): void
    {
        self::assertFalse($this->accounts(new RecordedTrust())->steers('alpha', RecordedTrust::TRIAGE));
    }

    public function testAProjectWithoutAGitHubRepositoryIsNotChecked(): void
    {
        $this->expectExceptionObject(TrustNotChecked::becauseTheRepositoryIsMalformed());

        $this->accounts(new RecordedTrust())->steers('beta', RecordedTrust::WRITER);
    }

    private function accounts(RecordedTrust $github): ProjectSteeringAccounts
    {
        $clock = new MockClock('2026-11-25 10:00:00 UTC');
        $http = $github->client();
        $jwt = new AppJwt('12345', AppKey::generate()->private, $clock);
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, $http, new NullLogger()), '678', $clock);
        $expected = ExpectedInstallation::fromSettings('contents:write,pull_requests:write,workflows:write', 'acme/widget,acme/gadget');
        $calls = new GitHubCalls($http, FreshReadings::at($clock), $tokens);
        $checked = new CheckedInstallationTokens(new InstallationReadBack(new InstallationReader($jwt, $tokens, $calls), $expected, new NullLogger()), $tokens);
        $answers = new TrustAnswers($calls);
        $trust = new SteeringTrustCheck(new SteeringIdentities($answers, $jwt, RecordedTrust::MAINTAINER_SLUG), $answers, $checked);

        return new ProjectSteeringAccounts(new WatchedRepositories(['alpha' => ['github' => 'acme/widget'], 'beta' => []]), $trust);
    }
}
