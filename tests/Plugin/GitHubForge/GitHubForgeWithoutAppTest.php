<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\Forge\Contract\HeadStatus;
use Intakt\Plugin\Forge\Contract\PublishedVerdict;
use Intakt\Plugin\Forge\Contract\ReviewToken;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
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
use Intakt\Plugin\GitHubForge\Infrastructure\RateLimitReadings;
use Intakt\Plugin\GitHubForge\Infrastructure\SecretScan;
use Intakt\Plugin\GitHubForge\Infrastructure\VerdictLocks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(GitHubForge::class)]
#[CoversClass(GitHubAppSetting::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(ForgeWriteFailed::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(PublishedVerdict::class)]
#[UsesClass(ReviewToken::class)]
#[UsesClass(PostedComments::class)]
#[UsesClass(SecretScan::class)]
#[UsesClass(RateLimitReadings::class)]
#[UsesClass(InstallationTokenMinter::class)]
#[UsesClass(InstallationTokens::class)]
#[UsesClass(AppJwt::class)]
#[UsesClass(CheckedInstallationTokens::class)]
#[UsesClass(InstallationReadBack::class)]
#[UsesClass(InstallationReader::class)]
#[UsesClass(GitHubCalls::class)]
#[UsesClass(ExpectedInstallation::class)]
final class GitHubForgeWithoutAppTest extends TestCase
{
    private const string PULL = 'https://github.com/acme/widgets/pull/7';
    private const string NOTICE = 'No GitHub App is configured (GITHUB_APP_ID is empty), so verdicts are not published to GitHub; the review page and MCP show them.';

    /** @var list<string> */
    private array $requests = [];

    private BufferingLogger $log;

    protected function setUp(): void
    {
        $this->log = new BufferingLogger();
    }

    public function testAVerdictIsNotPublishedAndNoRequestReachesGitHubWithoutAnApp(): void
    {
        $published = $this->forge('')->publishVerdict('alpha', new ChangeId(self::PULL), $this->verdict());

        self::assertFalse($published);
        self::assertSame([], $this->requests);
    }

    public function testTwoVerdictsWithoutAnAppLogOneInfoLineAndNothingElse(): void
    {
        $forge = $this->forge('');

        $forge->publishVerdict('alpha', new ChangeId(self::PULL), $this->verdict());
        $forge->publishVerdict('alpha', new ChangeId(self::PULL), $this->verdict());

        self::assertSame([['info', self::NOTICE, []]], $this->log->cleanLogs());
    }

    public function testAnAppIdOfOnlySpacesCountsAsNoApp(): void
    {
        $this->forge('  ')->publishVerdict('alpha', new ChangeId(self::PULL), $this->verdict());

        self::assertSame([], $this->requests);
        self::assertSame([['info', self::NOTICE, []]], $this->log->cleanLogs());
    }

    // GitHub refuses every request here, so the failed write is the proof that the forge tried.
    public function testAVerdictWithAnAppReachesGitHubAndLogsNoNotice(): void
    {
        try {
            $this->forge('12345')->publishVerdict('alpha', new ChangeId(self::PULL), $this->verdict());
            self::fail('The refused write must fail.');
        } catch (ForgeWriteFailed) {
        }

        self::assertSame(['POST https://api.github.com/app/installations/678/access_tokens'], $this->requests);
        self::assertSame([], $this->log->cleanLogs());
    }

    private function verdict(): PublishedVerdict
    {
        return new PublishedVerdict(new CommitId(str_repeat('1', 40)), HeadStatus::Success, 'Intakt approves this head.', 'The comment.', new ReviewToken('round-token-0001'));
    }

    // Every request is recorded and refused, so a forge that reaches GitHub at all leaves a trace in the list.
    private function forge(string $appId): GitHubForge
    {
        $clock = new MockClock('2026-11-25 10:00:00 UTC');
        $http = new MockHttpClient(function (string $method, string $url): ResponseInterface {
            $this->requests[] = $method.' '.$url;

            return new MockResponse('', ['http_code' => 503]);
        }, 'https://api.github.com');
        $jwt = new AppJwt('12345', AppKey::generate()->private, $clock);
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, $http, new NullLogger()), '678', $clock);
        $readings = FreshReadings::at($clock);
        $calls = new GitHubCalls($http, $readings, $tokens);
        $expected = ExpectedInstallation::fromSettings('contents:write', 'acme/widgets');
        $checked = new CheckedInstallationTokens(new InstallationReadBack(new InstallationReader($jwt, $tokens, $calls), $expected, new NullLogger()), $tokens);

        return new GitHubForge($calls, $checked, new SecretScan(dirname(__DIR__, 3), 'gitleaks'), new PostedComments($calls, $checked, '12345'), new VerdictLocks(new LockFactory(new InMemoryStore())), new GitHubAppSetting($appId, $this->log));
    }
}
