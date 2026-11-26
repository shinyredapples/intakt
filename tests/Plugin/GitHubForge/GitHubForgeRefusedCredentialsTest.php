<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Closure;
use Intakt\Plugin\Forge\Contract\Change;
use Intakt\Plugin\Forge\Contract\ChangedFiles;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitRange;
use Intakt\Plugin\Forge\Contract\Diff;
use Intakt\Plugin\Forge\Contract\ForgeCredentialsRefused;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\Forge\Contract\JobCredentials;
use Intakt\Plugin\Forge\Contract\ReviewComment;
use Intakt\Plugin\Forge\Contract\ReviewToken;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Domain\Installation;
use Intakt\Plugin\GitHubForge\Domain\RateLimitReading;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\CheckedInstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubAppSetting;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubForge;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\PostedComments;
use Intakt\Plugin\GitHubForge\Infrastructure\PullRequestAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\RateLimitReadings;
use Intakt\Plugin\GitHubForge\Infrastructure\SecretScan;
use Intakt\Plugin\GitHubForge\Infrastructure\VerdictLocks;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

#[CoversClass(GitHubForge::class)]
#[UsesClass(GitHubCalls::class)]
#[UsesClass(RateLimitReadings::class)]
#[UsesClass(RateLimitReading::class)]
#[UsesClass(ForgeCredentialsRefused::class)]
#[UsesClass(ForgeReadFailed::class)]
#[UsesClass(ForgeWriteFailed::class)]
#[UsesClass(InstallationNotReadBack::class)]
#[UsesClass(InstallationTokenNotMinted::class)]
#[UsesClass(Change::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(ChangedFiles::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(CommitRange::class)]
#[UsesClass(Diff::class)]
#[UsesClass(JobCredentials::class)]
#[UsesClass(ReviewComment::class)]
#[UsesClass(ReviewToken::class)]
#[UsesClass(PullRequestAddress::class)]
#[UsesClass(SecretScan::class)]
#[UsesClass(InstallationToken::class)]
#[UsesClass(InstallationTokenMinter::class)]
#[UsesClass(InstallationTokens::class)]
#[UsesClass(AppJwt::class)]
#[UsesClass(CheckedInstallationTokens::class)]
#[UsesClass(InstallationReadBack::class)]
#[UsesClass(InstallationReader::class)]
#[UsesClass(ExpectedInstallation::class)]
#[UsesClass(Installation::class)]
final class GitHubForgeRefusedCredentialsTest extends TestCase
{
    private const string PULL = 'https://github.com/acme/widgets/pull/7';
    private const string HEAD = '1111111111111111111111111111111111111111';
    private const string MERGE_BASE = '3333333333333333333333333333333333333333';

    private MockClock $clock;

    public function testAReadAnswered401FailsWithRefusedCredentialsAsItsCause(): void
    {
        $forge = $this->forgeAnswering(static fn (): MockResponse => new MockResponse('{"message":"Bad credentials"}', ['http_code' => 401]));

        $failure = $this->failureOf(static fn () => $forge->change(new ChangeId(self::PULL)));

        self::assertInstanceOf(ForgeReadFailed::class, $failure);
        self::assertInstanceOf(ForgeCredentialsRefused::class, $failure->getPrevious());
    }

    public function testAWriteAnswered401FailsWithRefusedCredentialsAsItsCause(): void
    {
        $forge = $this->forgeAnswering(static fn (string $url): MockResponse => str_ends_with($url, '/reviews') ? new MockResponse('{"message":"Bad credentials"}', ['http_code' => 401]) : self::plain($url));

        $failure = $this->failureOf(fn () => $forge->postReview('widgets', $this->change(), new ReviewComment('Two reviewers approve.', new ReviewToken('round-7-token-0123456789'), new JobCredentials())));

        self::assertInstanceOf(ForgeWriteFailed::class, $failure);
        self::assertInstanceOf(ForgeCredentialsRefused::class, $failure->getPrevious());
    }

    public function testAReadAnswered500CarriesNoRefusedCredentialsCause(): void
    {
        $forge = $this->forgeAnswering(static fn (): MockResponse => new MockResponse('{}', ['http_code' => 500]));

        $failure = $this->failureOf(static fn () => $forge->change(new ChangeId(self::PULL)));

        self::assertInstanceOf(ForgeReadFailed::class, $failure);
        self::assertNull($failure->getPrevious());
    }

    public function testAReadAnswered403CarriesNoRefusedCredentialsCause(): void
    {
        $forge = $this->forgeAnswering(static fn (): MockResponse => new MockResponse('{}', ['http_code' => 403]));

        $failure = $this->failureOf(static fn () => $forge->change(new ChangeId(self::PULL)));

        self::assertInstanceOf(ForgeReadFailed::class, $failure);
        self::assertNull($failure->getPrevious());
    }

    public function testATokenExchangeRefusedWith401ReachesAReadAsRefusedCredentials(): void
    {
        $forge = $this->forgeAnswering(static fn (string $url): MockResponse => self::plain($url), exchangeStatus: 401);

        $failure = $this->failureOf(static fn () => $forge->change(new ChangeId(self::PULL)));

        self::assertInstanceOf(ForgeReadFailed::class, $failure);
        self::assertInstanceOf(InstallationNotReadBack::class, $failure->getPrevious());
        self::assertInstanceOf(InstallationTokenNotMinted::class, $failure->getPrevious()->getPrevious());
        self::assertInstanceOf(ForgeCredentialsRefused::class, $failure->getPrevious()->getPrevious()->getPrevious());
    }

    public function testATokenExchangeRefusedWith401ReachesAWriteAsRefusedCredentials(): void
    {
        $forge = $this->forgeAnswering(static fn (string $url): MockResponse => self::plain($url), exchangeStatus: 401);

        $failure = $this->failureOf(fn () => $forge->postReview('widgets', $this->change(), new ReviewComment('Two reviewers approve.', new ReviewToken('round-7-token-0123456789'), new JobCredentials())));

        self::assertInstanceOf(ForgeWriteFailed::class, $failure);
        self::assertNotEmpty(array_filter($this->causesOf($failure), static fn (Throwable $cause): bool => $cause instanceof ForgeCredentialsRefused));
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-25 10:00:00 UTC');
    }

    /**
     * @return list<Throwable>
     */
    private function causesOf(Throwable $failure): array
    {
        $causes = [];
        for ($cause = $failure->getPrevious(); $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            $causes[] = $cause;
        }

        return $causes;
    }

    /**
     * @param Closure(string): MockResponse $answer
     */
    private function forgeAnswering(Closure $answer, int $exchangeStatus = 201): GitHubForge
    {
        $http = new MockHttpClient(static fn (string $method, string $url): ResponseInterface => $answer($url), 'https://api.github.com');
        $identity = new RecordedInstallation($this->clock, 'installation-secret')->client();
        $jwt = new AppJwt('12345', AppKey::generate()->private, $this->clock);
        $readings = FreshReadings::at($this->clock);
        $exchange = 201 === $exchangeStatus
            ? $identity
            : new MockHttpClient(new MockResponse('{"message":"Bad credentials"}', ['http_code' => $exchangeStatus]), 'https://api.github.com');
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, $exchange, new NullLogger()), '678', $this->clock);
        $expected = ExpectedInstallation::fromSettings('contents:write,pull_requests:write,workflows:write', 'acme/widget,acme/gadget');
        $readBack = new InstallationReadBack(new InstallationReader($jwt, $tokens, new GitHubCalls($identity, $readings, $tokens)), $expected, new NullLogger());

        $calls = new GitHubCalls($http, $readings, $tokens);
        $checked = new CheckedInstallationTokens($readBack, $tokens);

        return new GitHubForge($calls, $checked, new SecretScan(dirname(__DIR__, 3), 'gitleaks'), new PostedComments($calls, $checked, '12345'), new VerdictLocks(new LockFactory(new InMemoryStore())), new GitHubAppSetting('12345', new NullLogger()));
    }

    private function failureOf(Closure $call): Throwable
    {
        try {
            $call();
        } catch (ForgeReadFailed|ForgeWriteFailed $failure) {
            return $failure;
        }

        throw new LogicException('The call was expected to fail.');
    }

    private static function plain(string $url): MockResponse
    {
        return match (true) {
            str_ends_with($url, '/reviews') => new MockResponse(self::fixture('review_posted.json')),
            str_contains($url, '/pulls/7') => new MockResponse(self::fixture('pull_request.json')),
            default => new MockResponse(self::fixture('compare_merge_base.json')),
        };
    }

    private function change(): Change
    {
        return new Change(new ChangeId(self::PULL), new CommitRange(new CommitId(self::MERGE_BASE), new CommitId(self::HEAD)), new Diff('', new ChangedFiles()));
    }

    private static function fixture(string $name): string
    {
        $content = file_get_contents(__DIR__.'/Fixtures/'.$name);

        return false === $content ? throw new LogicException('Missing fixture '.$name) : $content;
    }
}
