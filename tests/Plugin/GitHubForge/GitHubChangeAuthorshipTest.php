<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Domain\Installation;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\CheckedInstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubChangeAuthorship;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\PullRequestAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\RateLimitReadings;
use Intakt\Plugin\GitHubForge\Infrastructure\SteeringIdentities;
use Intakt\Plugin\GitHubForge\Infrastructure\TrustAnswers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(GitHubChangeAuthorship::class)]
#[UsesClass(AppJwt::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(ChangeNotFound::class)]
#[UsesClass(CheckedInstallationTokens::class)]
#[UsesClass(ExpectedInstallation::class)]
#[UsesClass(GitHubCalls::class)]
#[UsesClass(Installation::class)]
#[UsesClass(InstallationNotReadBack::class)]
#[UsesClass(InstallationReadBack::class)]
#[UsesClass(InstallationReader::class)]
#[UsesClass(InstallationToken::class)]
#[UsesClass(InstallationTokenMinter::class)]
#[UsesClass(InstallationTokenNotMinted::class)]
#[UsesClass(InstallationTokens::class)]
#[UsesClass(PullRequestAddress::class)]
#[UsesClass(RateLimitReadings::class)]
#[UsesClass(SteeringIdentities::class)]
#[UsesClass(TrustAnswers::class)]
#[UsesClass(TrustNotChecked::class)]
final class GitHubChangeAuthorshipTest extends TestCase
{
    private RecordedTrust $github;
    private AppKey $key;
    private MockClock $clock;

    public function testAPullRequestThisAppsBotAccountCreatedIsCreatedByIntakt(): void
    {
        self::assertTrue($this->isCreatedByIntakt(RecordedTrust::PULL_BY_OWN_IDENTITY));
    }

    public function testTheAuthorIsReadFromThePullRequestWithTheInstallationTokenAndTheAppsSlugWithItsOwnProof(): void
    {
        $this->isCreatedByIntakt(RecordedTrust::PULL_BY_OWN_IDENTITY);

        self::assertSame('Authorization: Bearer minted-value', $this->github->credentials['https://api.github.com/repos/acme/widget/pulls/4']);
        self::assertStringStartsWith('Authorization: Bearer ey', $this->github->credentials['https://api.github.com/app']);
    }

    public function testAPullRequestAnotherAppCreatedIsNotCreatedByIntakt(): void
    {
        self::assertFalse($this->isCreatedByIntakt(RecordedTrust::PULL_BY_MAINTAINER));
    }

    public function testAPullRequestAPersonCreatedIsNotCreatedByIntaktAndTheAppIsNotAsked(): void
    {
        self::assertFalse($this->isCreatedByIntakt(RecordedTrust::PULL_BY_WRITER));
        self::assertNotContains('https://api.github.com/app', $this->github->requested);
    }

    public function testAPullRequestAnswerWithoutAnAuthorFailsTheRead(): void
    {
        $this->expectException(ForgeReadFailed::class);

        $this->isCreatedByIntakt(RecordedTrust::PULL_WITHOUT_AUTHOR);
    }

    public function testAPullRequestAnswerWithAnEmptyAuthorLoginFailsTheRead(): void
    {
        $this->expectException(ForgeReadFailed::class);

        $this->isCreatedByIntakt(RecordedTrust::PULL_WITH_EMPTY_AUTHOR);
    }

    public function testAPullRequestGitHubDoesNotFindFailsTheRead(): void
    {
        $this->expectException(ForgeReadFailed::class);

        $this->isCreatedByIntakt(RecordedTrust::PULL_UNKNOWN);
    }

    public function testAFailedTokenExchangeFailsTheReadWithoutReadingThePullRequest(): void
    {
        $this->github = new RecordedTrust(401);

        try {
            $this->isCreatedByIntakt(RecordedTrust::PULL_BY_OWN_IDENTITY);
            self::fail('A failed token exchange must fail the read.');
        } catch (ForgeReadFailed) {
            self::assertSame([], array_filter($this->github->requested, static fn (string $url): bool => str_contains($url, '/pulls/')));
        }
    }

    public function testAChangeThatIsNoGitHubPullRequestFailsTheReadAndAsksNothing(): void
    {
        try {
            $this->isCreatedByIntakt('https://example.com/acme/widget/pull/4');
            self::fail('A change that is no GitHub pull request must fail the read.');
        } catch (ForgeReadFailed) {
            self::assertSame([], $this->github->requested);
        }
    }

    protected function setUp(): void
    {
        $this->github = new RecordedTrust();
        $this->key = AppKey::generate();
        $this->clock = new MockClock('2026-11-25 10:00:00 UTC');
    }

    private function isCreatedByIntakt(string $change): bool
    {
        $http = $this->github->client();
        $readings = FreshReadings::at($this->clock);
        $tokens = new InstallationTokens(new InstallationTokenMinter($this->jwt(), $http, new RecordingLogger()), '678', $this->clock);
        $answers = new TrustAnswers(new GitHubCalls($http, $readings, $tokens));
        $expected = ExpectedInstallation::fromSettings('contents:write,pull_requests:write,workflows:write', 'acme/widget,acme/gadget');
        $checked = new CheckedInstallationTokens(new InstallationReadBack(new InstallationReader($this->jwt(), $tokens, new GitHubCalls($http, $readings, $tokens)), $expected, new RecordingLogger()), $tokens);

        return new GitHubChangeAuthorship($answers, $checked, new SteeringIdentities($answers, $this->jwt(), RecordedTrust::MAINTAINER_SLUG))->isCreatedByIntakt(new ChangeId($change));
    }

    private function jwt(): AppJwt
    {
        return new AppJwt('12345', $this->key->private, $this->clock);
    }
}
