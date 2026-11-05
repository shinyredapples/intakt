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
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Domain\Installation;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\CheckedInstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubForge;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\PullRequestAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\RateLimitReadings;
use Intakt\Plugin\GitHubForge\Infrastructure\SecretScan;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(GitHubForge::class)]
#[UsesClass(ForgeCredentialsRefused::class)]
#[UsesClass(ForgeReadFailed::class)]
#[UsesClass(ForgeWriteFailed::class)]
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
#[UsesClass(RateLimitReadings::class)]
#[UsesClass(GitHubCalls::class)]
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

    public function testItChainsRefusedCredentialsUnderAReadAnswered401(): void
    {
        $forge = $this->forgeAnswering(static fn (): MockResponse => new MockResponse('{"message":"Bad credentials"}', ['http_code' => 401]));

        $failure = $this->failureOf(static fn () => $forge->change(new ChangeId(self::PULL)));

        self::assertInstanceOf(ForgeReadFailed::class, $failure);
        self::assertInstanceOf(ForgeCredentialsRefused::class, $failure->getPrevious());
    }

    public function testItChainsNoRefusedCredentialsUnderAReadAnswered403(): void
    {
        $forge = $this->forgeAnswering(static fn (): MockResponse => new MockResponse('{}', ['http_code' => 403]));

        $failure = $this->failureOf(static fn () => $forge->change(new ChangeId(self::PULL)));

        self::assertInstanceOf(ForgeReadFailed::class, $failure);
        self::assertNull($failure->getPrevious());
    }

    public function testItChainsRefusedCredentialsUnderAWriteAnswered401(): void
    {
        $forge = $this->forgeAnswering(static fn (string $url): ?MockResponse => str_ends_with($url, '/reviews') ? new MockResponse('{"message":"Bad credentials"}', ['http_code' => 401]) : null);
        $change = new Change(new ChangeId(self::PULL), new CommitRange(new CommitId(self::MERGE_BASE), new CommitId(self::HEAD)), new Diff('', new ChangedFiles()));

        $failure = $this->failureOf(static fn () => $forge->postReview('widgets', $change, new ReviewComment('Looks good.', new ReviewToken('round-7-token-0123456789'), new JobCredentials())));

        self::assertInstanceOf(ForgeWriteFailed::class, $failure);
        self::assertInstanceOf(ForgeCredentialsRefused::class, $failure->getPrevious());
    }

    private function failureOf(Closure $call): RuntimeException
    {
        try {
            $call();
        } catch (RuntimeException $failure) {
            return $failure;
        }

        throw new LogicException('The call was expected to fail.');
    }

    /**
     * @param Closure(string): ?MockResponse $answer
     */
    private function forgeAnswering(Closure $answer): GitHubForge
    {
        $clock = new MockClock('2026-11-25 10:00:00 UTC');
        $readings = FreshReadings::at($clock);
        $http = new MockHttpClient(static fn (string $method, string $url): ResponseInterface => $answer($url) ?? new MockResponse(self::fixture('pull_request.json')), 'https://api.github.com');
        $identity = new RecordedInstallation('installation-secret')->client();
        $jwt = new AppJwt('12345', AppKey::generate()->private, $clock);
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, $identity, new NullLogger()), '678', $clock);
        $expected = ExpectedInstallation::fromSettings('contents:write,pull_requests:write,workflows:write', 'acme/widget,acme/gadget');
        $readBack = new InstallationReadBack(new InstallationReader($jwt, $tokens, new GitHubCalls($identity, $readings, $tokens)), $expected, new NullLogger());

        return new GitHubForge(new GitHubCalls($http, $readings, $tokens), new CheckedInstallationTokens($readBack, $tokens), new SecretScan(dirname(__DIR__, 3), 'gitleaks'));
    }

    private static function fixture(string $name): string
    {
        $content = file_get_contents(__DIR__.'/Fixtures/'.$name);

        return false === $content ? throw new LogicException('Missing fixture '.$name) : $content;
    }
}
