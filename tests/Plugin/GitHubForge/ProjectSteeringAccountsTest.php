<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
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
#[UsesClass(WatchedRepositories::class)]
#[UsesClass(RepositoryAddress::class)]
#[UsesClass(SteeringTrustCheck::class)]
#[UsesClass(SteeringIdentities::class)]
#[UsesClass(SteeringTrust::class)]
#[UsesClass(TrustAnswers::class)]
#[UsesClass(TrustNotChecked::class)]
#[UsesClass(AppJwt::class)]
#[UsesClass(CheckedInstallationTokens::class)]
#[UsesClass(ExpectedInstallation::class)]
#[UsesClass(Installation::class)]
#[UsesClass(InstallationReadBack::class)]
#[UsesClass(InstallationReader::class)]
#[UsesClass(GitHubCalls::class)]
#[UsesClass(RateLimitReadings::class)]
#[UsesClass(InstallationToken::class)]
#[UsesClass(InstallationTokenMinter::class)]
#[UsesClass(InstallationTokens::class)]
final class ProjectSteeringAccountsTest extends TestCase
{
    private MockClock $clock;

    private RecordedTrust $github;

    public function testAnAccountWithWriteAccessToTheProjectsRepositorySteers(): void
    {
        self::assertTrue($this->accounts()->steers('alpha', RecordedTrust::WRITER));
        self::assertContains('https://api.github.com/repos/acme/widget/collaborators/alice/permission', $this->github->requested);
    }

    public function testAnAccountWithTheTriageRoleDoesNotSteer(): void
    {
        self::assertFalse($this->accounts()->steers('alpha', RecordedTrust::TRIAGE));
    }

    public function testAnAccountWithReadAccessDoesNotSteer(): void
    {
        self::assertFalse($this->accounts()->steers('alpha', RecordedTrust::READ));
    }

    public function testAnAccountGitHubDoesNotKnowFailsInsteadOfNotSteering(): void
    {
        $this->expectExceptionObject(TrustNotChecked::becauseGitHubRefused(404));

        $this->accounts()->steers('alpha', 'stranger');
    }

    public function testAProjectWithoutAGitHubRepositoryFailsWithoutARequest(): void
    {
        try {
            $this->accounts()->steers('beta', RecordedTrust::WRITER);
            self::fail('An account was checked on a project without a GitHub repository.');
        } catch (ForgeReadFailed $failure) {
            self::assertSame('Project "beta" has no GitHub repository in its watch section.', $failure->getMessage());
        }

        self::assertSame([], $this->github->requested);
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-25 10:00:00 UTC');
        $this->github = new RecordedTrust($this->clock);
    }

    private function accounts(): ProjectSteeringAccounts
    {
        $clock = $this->clock;
        $http = $this->github->client();
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
