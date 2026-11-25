<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

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
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\RateLimitReadings;
use Intakt\Plugin\GitHubForge\Infrastructure\SteeringIdentities;
use Intakt\Plugin\GitHubForge\Infrastructure\SteeringTrustCheck;
use Intakt\Plugin\GitHubForge\Infrastructure\TrustAnswers;
use InvalidArgumentException;
use NoDiscard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[CoversClass(SteeringTrustCheck::class)]
#[CoversClass(SteeringIdentities::class)]
#[CoversClass(TrustAnswers::class)]
#[CoversClass(SteeringTrust::class)]
#[CoversClass(TrustNotChecked::class)]
#[UsesClass(AppJwt::class)]
#[UsesClass(CheckedInstallationTokens::class)]
#[UsesClass(ExpectedInstallation::class)]
#[UsesClass(Installation::class)]
#[UsesClass(InstallationNotReadBack::class)]
#[UsesClass(InstallationReadBack::class)]
#[UsesClass(InstallationReader::class)]
#[UsesClass(GitHubCalls::class)]
#[UsesClass(RateLimitReadings::class)]
#[UsesClass(ForgeRateLimited::class)]
#[UsesClass(InstallationToken::class)]
#[UsesClass(InstallationTokenMinter::class)]
#[UsesClass(InstallationTokenNotMinted::class)]
#[UsesClass(InstallationTokens::class)]
final class SteeringTrustCheckTest extends TestCase
{
    private const string REPOSITORY = 'acme/widget';

    private RecordedTrust $github;
    private RecordingLogger $logger;
    private AppKey $key;
    private MockClock $clock;
    private RateLimitReadings $readings;

    public function testAPermissionReadWaitsForTheResetOfTheInstallation(): void
    {
        $this->pauseTheInstallation();

        try {
            $this->answers()->ofInstallation('/repos/acme/widget/collaborators/alice/permission', 'minted-value');
            self::fail('The permission was read while the installation waits for its reset.');
        } catch (ForgeRateLimited $limited) {
            self::assertEquals($this->clock->now()->modify('+600 seconds'), $limited->resetsAt);
        }

        self::assertSame([], $this->github->requested);
    }

    public function testTheAppsOwnReadIsNotHeldByThePauseOfTheInstallation(): void
    {
        $this->pauseTheInstallation();

        $answer = $this->answers()->ofApp('/app', $this->jwt()->sign());

        self::assertSame('acme-intakt', $answer['slug'] ?? null);
    }

    public function testAnAccountWithWriteAccessSteers(): void
    {
        $trust = $this->check(RecordedTrust::WRITER);

        self::assertSame(SteeringTrust::Writer, $trust);
        self::assertTrue($trust->steers());
    }

    public function testTheWriteAccessIsAskedWithTheInstallationToken(): void
    {
        (void) $this->check(RecordedTrust::WRITER);

        self::assertSame('Authorization: Bearer minted-value', $this->github->credentials['https://api.github.com/repos/acme/widget/collaborators/alice/permission']);
    }

    public function testAnAccountWithTheTriageRoleDoesNotSteer(): void
    {
        $trust = $this->check(RecordedTrust::TRIAGE);

        self::assertSame(SteeringTrust::NonWriter, $trust);
        self::assertFalse($trust->steers());
    }

    public function testAForeignBotNeverSteersAndItsPermissionIsNotAsked(): void
    {
        $trust = $this->check(RecordedTrust::FOREIGN_BOT);

        self::assertSame(SteeringTrust::ForeignBot, $trust);
        self::assertFalse($trust->steers());
        self::assertSame([], array_filter($this->github->requested, static fn (string $url): bool => str_contains($url, '/collaborators/')));
    }

    public function testAnAccountGitHubCallsABotDoesNotSteerWhateverItsPermission(): void
    {
        self::assertSame(SteeringTrust::ForeignBot, $this->check(RecordedTrust::BOT_BY_TYPE));
    }

    public function testIntaktsOwnIdentityReadFromGitHubSteers(): void
    {
        $trust = $this->check(RecordedTrust::OWN_IDENTITY);

        self::assertSame(SteeringTrust::OwnIdentity, $trust);
        self::assertTrue($trust->steers());
        self::assertContains('https://api.github.com/app', $this->github->requested);
    }

    public function testIntaktsOwnIdentityIsAskedForWriteAccessWithTheInstallationToken(): void
    {
        (void) $this->check(RecordedTrust::OWN_IDENTITY);

        self::assertSame('Authorization: Bearer minted-value', $this->github->credentials['https://api.github.com/repos/acme/widget/collaborators/acme-intakt%5Bbot%5D/permission']);
    }

    public function testIntaktsOwnIdentityDoesNotSteerWhenTheTokenExchangeFails(): void
    {
        $this->github = new RecordedTrust($this->clock, 401);

        $this->expectException(TrustNotChecked::class);

        (void) $this->check(RecordedTrust::OWN_IDENTITY);
    }

    public function testTheMaintainersIdentityDoesNotSteerWhenTheTokenExchangeFails(): void
    {
        $this->github = new RecordedTrust($this->clock, 401);

        try {
            (void) $this->check(RecordedTrust::MAINTAINER);
            self::fail('The maintainer steered without a token.');
        } catch (TrustNotChecked $failure) {
            self::assertStringContainsString('status 401', $failure->getMessage());
        }
    }

    public function testATrustedBotWithoutWriteAccessDoesNotSteer(): void
    {
        $trust = $this->checkWith(RecordedTrust::READER, RecordedTrust::READER_SLUG);

        self::assertSame(SteeringTrust::NonWriter, $trust);
        self::assertFalse($trust->steers());
    }

    public function testATrustedBotWhosePermissionIsRefusedFailsTheCheck(): void
    {
        $this->expectExceptionObject(TrustNotChecked::becauseGitHubRefused(404));

        (void) $this->checkWith('acme-gone[bot]', 'acme-gone');
    }

    public function testIntaktsOwnIdentityIsRecognizedWhateverTheCaseOfTheLogin(): void
    {
        self::assertSame(SteeringTrust::OwnIdentity, $this->check('Acme-Intakt[bot]'));
    }

    public function testABotNamedLikeIntaktButNotItDoesNotSteer(): void
    {
        self::assertSame(SteeringTrust::ForeignBot, $this->check('acme-intakt-helper[bot]'));
    }

    public function testTheMaintainersIdentitySteers(): void
    {
        $trust = $this->check(RecordedTrust::MAINTAINER);

        self::assertSame(SteeringTrust::Maintainer, $trust);
        self::assertTrue($trust->steers());
        self::assertContains('https://api.github.com/repos/acme/widget/collaborators/acme-intakt-maintainer%5Bbot%5D/permission', $this->github->requested);
    }

    public function testWithoutAMaintainerConfiguredTheMaintainersBotDoesNotSteer(): void
    {
        self::assertSame(SteeringTrust::ForeignBot, $this->checkWith(RecordedTrust::MAINTAINER, ''));
    }

    public function testAMaintainerSettingThatIsNoSlugIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (void) $this->checkWith(RecordedTrust::WRITER, 'acme/maintainer');
    }

    public function testEachCheckAsksGitHubAgain(): void
    {
        $trust = $this->trust(RecordedTrust::MAINTAINER_SLUG);

        (void) $trust->check(self::REPOSITORY, RecordedTrust::WRITER);
        (void) $trust->check(self::REPOSITORY, RecordedTrust::WRITER);

        self::assertCount(2, array_filter($this->github->requested, static fn (string $url): bool => str_contains($url, '/collaborators/alice/permission')));
    }

    public function testAFailedTokenExchangeFailsTheCheckAndTriesNoOtherCredential(): void
    {
        $this->github = new RecordedTrust($this->clock, 401);

        try {
            (void) $this->check(RecordedTrust::WRITER);
            self::fail('The check passed without a token.');
        } catch (TrustNotChecked $failure) {
            self::assertStringContainsString('status 401', $failure->getMessage());
        }

        self::assertSame([], array_filter($this->github->requested, static fn (string $url): bool => !str_ends_with($url, '/access_tokens')));
    }

    public function testThePrivateKeyNeverAppearsInTheFailureOrTheLogs(): void
    {
        $this->github = new RecordedTrust($this->clock, 401);

        try {
            (void) $this->check(RecordedTrust::WRITER);
            self::fail('The check passed without a token.');
        } catch (TrustNotChecked $failure) {
            $written = $failure->getMessage().implode("\n", $this->logger->lines);
        }

        self::assertNotSame([], $this->logger->lines);
        self::assertStringNotContainsString(substr($this->key->body(), 0, 64), $written);
        self::assertStringNotContainsString(trim(explode(' ', $this->github->credentials['https://api.github.com/app/installations/678/access_tokens'])[2]), $written);
    }

    public function testAKeyThatCannotSignFailsTheCheckOfABot(): void
    {
        $trust = new SteeringTrustCheck(
            new SteeringIdentities($this->answers(), new AppJwt('12345', 'not a key', $this->clock), ''),
            $this->answers(),
            $this->tokens(),
        );

        $this->expectExceptionObject(TrustNotChecked::becauseNoCredential('The GitHub App private key cannot be read or cannot sign.'));

        (void) $trust->check(self::REPOSITORY, RecordedTrust::OWN_IDENTITY);
    }

    public function testAnAnswerAboutAnotherAccountFailsTheCheck(): void
    {
        $this->expectExceptionObject(TrustNotChecked::becauseTheAnswerIsInvalid());

        (void) $this->check('impostor');
    }

    public function testAPermissionLevelGitHubDoesNotDocumentFailsTheCheck(): void
    {
        $this->expectExceptionObject(TrustNotChecked::becauseTheAnswerIsInvalid());

        (void) $this->check('carol');
    }

    public function testARefusedPermissionReadFailsTheCheckInsteadOfDenying(): void
    {
        $this->expectExceptionObject(TrustNotChecked::becauseGitHubRefused(404));

        (void) $this->check('stranger');
    }

    public function testAMalformedAccountFailsTheCheckWithoutARequest(): void
    {
        try {
            (void) $this->check('alice/../../admin');
            self::fail('A malformed account was checked.');
        } catch (TrustNotChecked $failure) {
            self::assertEquals(TrustNotChecked::becauseTheAccountIsMalformed(), $failure);
        }

        self::assertSame([], $this->github->requested);
    }

    public function testAMalformedRepositoryFailsTheCheckWithoutARequest(): void
    {
        try {
            (void) $this->trust(RecordedTrust::MAINTAINER_SLUG)->check('acme/..', RecordedTrust::WRITER);
            self::fail('A malformed repository was checked.');
        } catch (TrustNotChecked $failure) {
            self::assertEquals(TrustNotChecked::becauseTheRepositoryIsMalformed(), $failure);
        }

        self::assertSame([], $this->github->requested);
    }

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->key = AppKey::generate();
        $this->clock = new MockClock('2026-11-25 10:00:00 UTC');
        $this->github = new RecordedTrust($this->clock);
        $this->readings = FreshReadings::at($this->clock);
    }

    #[NoDiscard]
    private function check(string $login): SteeringTrust
    {
        return $this->checkWith($login, RecordedTrust::MAINTAINER_SLUG);
    }

    #[NoDiscard]
    private function checkWith(string $login, string $maintainerSlug): SteeringTrust
    {
        return $this->trust($maintainerSlug)->check(self::REPOSITORY, $login);
    }

    private function trust(string $maintainerSlug): SteeringTrustCheck
    {
        $answers = $this->answers();

        return new SteeringTrustCheck(new SteeringIdentities($answers, $this->jwt(), $maintainerSlug), $answers, $this->tokens());
    }

    private function tokens(): CheckedInstallationTokens
    {
        $http = $this->github->client();
        $tokens = $this->installationTokens($http);
        $expected = ExpectedInstallation::fromSettings('contents:write,pull_requests:write,workflows:write', 'acme/widget,acme/gadget');

        return new CheckedInstallationTokens(new InstallationReadBack(new InstallationReader($this->jwt(), $tokens, new GitHubCalls($http, $this->readings, $tokens)), $expected, $this->logger), $tokens);
    }

    private function pauseTheInstallation(): void
    {
        try {
            $this->readings->observe('678', 429, ['retry-after' => ['600']]);
        } catch (ForgeRateLimited) {
        }
    }

    private function answers(): TrustAnswers
    {
        $http = $this->github->client();

        return new TrustAnswers(new GitHubCalls($http, $this->readings, $this->installationTokens($http)));
    }

    private function installationTokens(HttpClientInterface $http): InstallationTokens
    {
        return new InstallationTokens(new InstallationTokenMinter($this->jwt(), $http, $this->logger), '678', $this->clock);
    }

    private function jwt(): AppJwt
    {
        return new AppJwt('12345', $this->key->private, $this->clock);
    }
}
