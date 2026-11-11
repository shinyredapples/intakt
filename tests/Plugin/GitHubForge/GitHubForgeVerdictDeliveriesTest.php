<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Closure;
use Fiber;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\Forge\Contract\HeadStatus;
use Intakt\Plugin\Forge\Contract\PublishedVerdict;
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
use Intakt\Plugin\GitHubForge\Infrastructure\PostedComments;
use Intakt\Plugin\GitHubForge\Infrastructure\PullRequestAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\RateLimitReadings;
use Intakt\Plugin\GitHubForge\Infrastructure\SecretScan;
use Intakt\Plugin\GitHubForge\Infrastructure\VerdictLocks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Contracts\HttpClient\ResponseInterface;

// Deliveries of one round's verdict as two workers meet them, so the forge is shown to leave one comment however their reads and posts interleave.
#[CoversClass(VerdictLocks::class)]
#[CoversClass(GitHubForge::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(ForgeWriteFailed::class)]
#[UsesClass(PublishedVerdict::class)]
#[UsesClass(ReviewToken::class)]
#[UsesClass(PostedComments::class)]
#[UsesClass(PullRequestAddress::class)]
#[UsesClass(SecretScan::class)]
#[UsesClass(RateLimitReadings::class)]
#[UsesClass(InstallationToken::class)]
#[UsesClass(InstallationTokenMinter::class)]
#[UsesClass(InstallationTokens::class)]
#[UsesClass(AppJwt::class)]
#[UsesClass(CheckedInstallationTokens::class)]
#[UsesClass(InstallationReadBack::class)]
#[UsesClass(InstallationReader::class)]
#[UsesClass(GitHubCalls::class)]
#[UsesClass(ExpectedInstallation::class)]
#[UsesClass(Installation::class)]
final class GitHubForgeVerdictDeliveriesTest extends TestCase
{
    private const string PULL = 'https://github.com/acme/widgets/pull/7';
    private const string HEAD = '1111111111111111111111111111111111111111';
    private const string API = 'https://api.github.com/repos/acme/widgets';
    private const string LISTING = '/issues/7/comments?';
    private const string POST_COMMENT = 'POST '.self::API.'/issues/7/comments';
    private const string POST_STATUS = 'POST '.self::API.'/statuses/'.self::HEAD;

    /** @var list<string> */
    private array $posts = [];

    public function testTwoDeliveriesThatBothReachTheReadOfTheCommentsPostOneComment(): void
    {
        // The first delivery stops inside its read of the comments, so the second one starts while no comment is posted yet.
        $forge = $this->forge(static function (string $url): ResponseInterface {
            if (str_contains($url, self::LISTING)) {
                Fiber::suspend();
            }

            return str_contains($url, self::LISTING) ? new MockResponse('[]') : new MockResponse('{}', ['http_code' => 201]);
        });
        $first = new Fiber(fn () => $this->publish($forge, 'The verdict.'));
        $second = new Fiber(fn () => $this->publish($forge, 'The verdict.'));

        $first->start();
        $busy = $this->failureOf(static fn () => $second->start());
        $first->resume();

        self::assertSame('Another delivery is publishing this round\'s verdict.', $busy->getMessage());
        self::assertSame([self::POST_COMMENT, self::POST_STATUS], $this->posts);
    }

    public function testADeliveryWhosePostFailedLeavesTheRoundFreeForItsRetry(): void
    {
        $refused = false;
        $forge = $this->forge(static function (string $url) use (&$refused): ResponseInterface {
            $refuse = !$refused && str_contains($url, '/statuses/');
            $refused = $refused || $refuse;

            return new MockResponse('{}', ['http_code' => $refuse ? 502 : 201]);
        });
        $this->failureOf(fn () => $this->publish($forge, null));

        $this->publish($forge, null);

        self::assertSame([self::POST_STATUS, self::POST_STATUS], $this->posts);
    }

    private function publish(GitHubForge $forge, ?string $comment): void
    {
        $status = null === $comment ? HeadStatus::Error : HeadStatus::Success;
        $forge->publishVerdict('widgets', new ChangeId(self::PULL), new PublishedVerdict(new CommitId(self::HEAD), $status, 'The description.', $comment, new ReviewToken('round-7-token-0123456789')));
    }

    private function failureOf(Closure $delivery): ForgeWriteFailed
    {
        try {
            $delivery();
        } catch (ForgeWriteFailed $failure) {
            return $failure;
        }

        self::fail('The delivery must fail.');
    }

    /**
     * @param Closure(string): ResponseInterface $answer
     */
    private function forge(Closure $answer): GitHubForge
    {
        $clock = new MockClock('2026-11-25 10:00:00 UTC');
        $identity = new RecordedInstallation('installation-secret')->client();
        $jwt = new AppJwt('12345', AppKey::generate()->private, $clock);
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, $identity, new NullLogger()), '678', $clock);
        $expected = ExpectedInstallation::fromSettings('contents:write,pull_requests:write,workflows:write', 'acme/widget,acme/gadget');
        $readings = FreshReadings::at($clock);
        $readBack = new InstallationReadBack(new InstallationReader($jwt, $tokens, new GitHubCalls($identity, $readings, $tokens)), $expected, new NullLogger());
        // Retrying as the scoped client is in the container, because a write's `max_retries` option needs it.
        $http = new RetryableHttpClient(new MockHttpClient(function (string $method, string $url) use ($answer): ResponseInterface {
            if ('POST' === $method) {
                $this->posts[] = $method.' '.$url;
            }

            return $answer($url);
        }, 'https://api.github.com'), new GenericRetryStrategy(delayMs: 0), 2);

        $calls = new GitHubCalls($http, $readings, $tokens);
        $checked = new CheckedInstallationTokens($readBack, $tokens);

        return new GitHubForge($calls, $checked, new SecretScan(dirname(__DIR__, 3), 'gitleaks'), new PostedComments($calls, $checked, '12345'), new VerdictLocks(new LockFactory(new InMemoryStore())));
    }
}
