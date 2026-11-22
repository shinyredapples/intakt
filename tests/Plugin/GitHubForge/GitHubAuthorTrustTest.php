<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use Intakt\Plugin\GitHubForge\Contract\TrustNotChecked;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Domain\Installation;
use Intakt\Plugin\GitHubForge\Domain\SteeringTrust;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\CheckedInstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubAuthorTrust;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\PullRequestAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\RateLimitReadings;
use Intakt\Plugin\GitHubForge\Infrastructure\SteeringIdentities;
use Intakt\Plugin\GitHubForge\Infrastructure\SteeringTrustCheck;
use Intakt\Plugin\GitHubForge\Infrastructure\TrustAnswers;
use NoDiscard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversClass(GitHubAuthorTrust::class)]
#[UsesClass(AppJwt::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(ChangeNotFound::class)]
#[UsesClass(CheckedInstallationTokens::class)]
#[UsesClass(ExpectedInstallation::class)]
#[UsesClass(ForgeRateLimited::class)]
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
#[UsesClass(SteeringTrust::class)]
#[UsesClass(SteeringTrustCheck::class)]
#[UsesClass(TrustAnswers::class)]
#[UsesClass(TrustNotChecked::class)]
final class GitHubAuthorTrustTest extends TestCase
{
    private RecordedTrust $github;
    private RecordingLogger $logger;
    private AppKey $key;
    private MockClock $clock;
    private RateLimitReadings $readings;

    protected function setUp(): void
    {
        $this->github = new RecordedTrust();
        $this->logger = new RecordingLogger();
        $this->key = AppKey::generate();
        $this->clock = new MockClock('2026-11-25 10:00:00 UTC');
        $this->readings = FreshReadings::at($this->clock);
    }

    public function testAnAuthorWithWriteAccessIsTrusted(): void
    {
        self::assertTrue($this->isTrusted(RecordedTrust::PULL_BY_WRITER));
    }

    public function testTheAuthorIsReadFromThePullRequestWithTheInstallationToken(): void
    {
        (void) $this->isTrusted(RecordedTrust::PULL_BY_WRITER);

        self::assertSame('Authorization: Bearer minted-value', $this->github->credentials['https://api.github.com/repos/acme/widget/pulls/1']);
        self::assertContains('https://api.github.com/repos/acme/widget/collaborators/alice/permission', $this->github->requested);
    }

    public function testAnAuthorWithoutWriteAccessIsNotTrusted(): void
    {
        self::assertFalse($this->isTrusted(RecordedTrust::PULL_BY_TRIAGE));
        self::assertSame([], $this->logger->lines);
    }

    public function testAForeignBotIsNotTrusted(): void
    {
        self::assertFalse($this->isTrusted(RecordedTrust::PULL_BY_FOREIGN_BOT));
    }

    public function testIntaktsOwnIdentityIsTrusted(): void
    {
        self::assertTrue($this->isTrusted(RecordedTrust::PULL_BY_OWN_IDENTITY));
    }

    public function testTheMaintainersIdentityIsTrusted(): void
    {
        self::assertTrue($this->isTrusted(RecordedTrust::PULL_BY_MAINTAINER));
    }

    public function testAnAuthorWhoseTrustCheckFailsIsNotTrustedAndTheFailureIsLogged(): void
    {
        self::assertFalse($this->isTrusted(RecordedTrust::PULL_BY_STRANGER));
        self::assertCount(1, $this->logger->lines);
        self::assertStringContainsString('could not answer', $this->logger->lines[0]);
    }

    public function testAPullRequestGitHubDoesNotFindHasNoTrustedAuthor(): void
    {
        self::assertFalse($this->isTrusted(RecordedTrust::PULL_UNKNOWN));
        self::assertCount(1, $this->logger->lines);
    }

    public function testAPullRequestAnswerWithoutAnAuthorHasNoTrustedAuthor(): void
    {
        self::assertFalse($this->isTrusted(RecordedTrust::PULL_WITHOUT_AUTHOR));
        self::assertSame([], array_filter($this->github->requested, static fn (string $url): bool => str_contains($url, '/collaborators/')));
    }

    public function testAWritersPullRequestFromAForkIsNotTrusted(): void
    {
        self::assertFalse($this->isTrusted(RecordedTrust::PULL_FROM_FORK));
        self::assertSame([], array_filter($this->github->requested, static fn (string $url): bool => str_contains($url, '/collaborators/')));
    }

    public function testAWritersPullRequestWhoseForkWasDeletedIsNotTrusted(): void
    {
        self::assertFalse($this->isTrusted(RecordedTrust::PULL_FROM_DELETED_FORK));
        self::assertSame([], $this->logger->lines);
        self::assertSame([], array_filter($this->github->requested, static fn (string $url): bool => str_contains($url, '/collaborators/')));
    }

    public function testAPullRequestAnswerWithoutAHeadRepositoryIsNotTrustedAndLogged(): void
    {
        self::assertFalse($this->isTrusted(RecordedTrust::PULL_WITHOUT_HEAD_REPO));
        self::assertCount(1, $this->logger->lines);
    }

    public function testAFailedTokenExchangeLeavesTheAuthorUntrusted(): void
    {
        $this->github = new RecordedTrust(401);

        self::assertFalse($this->isTrusted(RecordedTrust::PULL_BY_WRITER));
        self::assertSame([], array_filter($this->github->requested, static fn (string $url): bool => str_contains($url, '/pulls/')));
    }

    public function testAReadTheInstallationsPauseRefusesLeavesTheAuthorUntrusted(): void
    {
        try {
            $this->readings->observe('678', 429, ['retry-after' => ['600']]);
        } catch (ForgeRateLimited) {
        }

        self::assertFalse($this->isTrusted(RecordedTrust::PULL_BY_WRITER));
        self::assertSame([], array_filter($this->github->requested, static fn (string $url): bool => str_contains($url, '/pulls/')));
    }

    public function testAChangeThatIsNoGitHubPullRequestIsNotTrustedAndAsksNothing(): void
    {
        self::assertFalse($this->isTrusted('https://example.com/acme/widget/pull/1'));
        self::assertSame([], $this->github->requested);
    }

    public function testEachQuestionReadsThePullRequestAgain(): void
    {
        $trust = $this->trust();

        (void) $trust->isAuthorTrusted(new ChangeId(RecordedTrust::PULL_BY_WRITER));
        (void) $trust->isAuthorTrusted(new ChangeId(RecordedTrust::PULL_BY_WRITER));

        self::assertCount(2, array_filter($this->github->requested, static fn (string $url): bool => str_ends_with($url, '/pulls/1')));
    }

    #[NoDiscard]
    private function isTrusted(string $change): bool
    {
        return $this->trust()->isAuthorTrusted(new ChangeId($change));
    }

    private function trust(): GitHubAuthorTrust
    {
        $http = $this->github->client();
        $tokens = $this->installationTokens($http);
        $answers = new TrustAnswers(new GitHubCalls($http, $this->readings, $tokens));
        $expected = ExpectedInstallation::fromSettings('contents:write,pull_requests:write,workflows:write', 'acme/widget,acme/gadget');
        $checked = new CheckedInstallationTokens(new InstallationReadBack(new InstallationReader($this->jwt(), $tokens, new GitHubCalls($http, $this->readings, $tokens)), $expected, new RecordingLogger()), $tokens);

        return new GitHubAuthorTrust(
            $answers,
            $checked,
            new SteeringTrustCheck(new SteeringIdentities($answers, $this->jwt(), RecordedTrust::MAINTAINER_SLUG), $answers, $checked),
            $this->logger,
        );
    }

    private function installationTokens(HttpClientInterface $http): InstallationTokens
    {
        return new InstallationTokens(new InstallationTokenMinter($this->jwt(), $http, new RecordingLogger()), '678', $this->clock);
    }

    private function jwt(): AppJwt
    {
        return new AppJwt('12345', $this->key->private, $this->clock);
    }
}
