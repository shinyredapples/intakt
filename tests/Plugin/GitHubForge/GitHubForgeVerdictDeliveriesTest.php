<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Closure;
use Fiber;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CommentBodies;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\Forge\Contract\HeadStatus;
use Intakt\Plugin\Forge\Contract\PublishedVerdict;
use Intakt\Plugin\Forge\Contract\ReviewTextHoldsSecret;
use Intakt\Plugin\Forge\Contract\ReviewToken;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Domain\Installation;
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
#[UsesClass(ReviewTextHoldsSecret::class)]
#[UsesClass(GitHubAppSetting::class)]
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

    private const string TOKEN = 'round-7-token-0123456789';

    /** @var list<string> */
    private array $posts = [];

    /** @var list<string> */
    private array $bodies = [];

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

    public function testAnOwnerAskIsPostedPartByPartWithAMarkerEachAfterTheReviewCommentAndBeforeTheStatus(): void
    {
        $forge = $this->forge(static fn (string $url): ResponseInterface => str_contains($url, self::LISTING) ? new MockResponse('[]') : new MockResponse('{}', ['http_code' => 201]));

        self::assertTrue($forge->publishVerdict('widgets', new ChangeId(self::PULL), $this->askingVerdict()));

        self::assertSame([self::POST_COMMENT, self::POST_COMMENT, self::POST_COMMENT, self::POST_STATUS], $this->posts);
        self::assertSame([
            "The verdict.\n\n<!-- intakt-verdict: ".self::TOKEN.' -->',
            "Ask, part 1.\n\n".$this->askMarker(1),
            "Ask, part 2.\n\n".$this->askMarker(2),
        ], array_slice($this->bodies, 0, 3));
    }

    public function testARetryWhoseReviewCommentIsPostedPostsOnlyTheAskPartsNotYetPosted(): void
    {
        $forge = $this->forgeThatListsAlreadyPosted(["The verdict.\n\n<!-- intakt-verdict: ".self::TOKEN.' -->', "Ask, part 1.\n\n".$this->askMarker(1)]);

        self::assertTrue($forge->publishVerdict('widgets', new ChangeId(self::PULL), $this->askingVerdict()));

        self::assertSame([self::POST_COMMENT, self::POST_STATUS], $this->posts);
        self::assertSame("Ask, part 2.\n\n".$this->askMarker(2), $this->bodies[0]);
    }

    public function testARetryWhoseProtectedPathsChangedSinceTheEarlierTryPostsTheAskItWillRecord(): void
    {
        $forge = $this->forgeThatListsAlreadyPosted(["The verdict.\n\n<!-- intakt-verdict: ".self::TOKEN.' -->', "Ask, part 1.\n\n".$this->askMarker(1, ['Ask, part 1.'])]);

        self::assertTrue($forge->publishVerdict('widgets', new ChangeId(self::PULL), $this->askingVerdict()));

        self::assertSame([self::POST_COMMENT, self::POST_COMMENT, self::POST_STATUS], $this->posts);
        self::assertSame(["Ask, part 1.\n\n".$this->askMarker(1), "Ask, part 2.\n\n".$this->askMarker(2)], array_slice($this->bodies, 0, 2));
    }

    public function testAnOwnerAskWhoseSecretIsWrittenWithEntitiesPostsNothing(): void
    {
        $forge = $this->forge(static fn (string $url): ResponseInterface => str_contains($url, self::LISTING) ? new MockResponse('[]') : new MockResponse('{}', ['http_code' => 201]));
        $escaped = '- <code>bin/sk&#95;live&#95;'.str_repeat('4eC39HqLyjWDarjtT1zdp7dc', 2).'</code>';
        $verdict = new PublishedVerdict(new CommitId(self::HEAD), HeadStatus::Pending, 'The description.', 'The verdict.', new ReviewToken(self::TOKEN), new CommentBodies($escaped));

        try {
            $forge->publishVerdict('widgets', new ChangeId(self::PULL), $verdict);
            self::fail('An ask that renders a secret must not be posted.');
        } catch (ReviewTextHoldsSecret) {
        }

        self::assertSame([], $this->posts);
    }

    public function testAnOwnerAskWithEntitiesButNoSecretIsPosted(): void
    {
        $forge = $this->forge(static fn (string $url): ResponseInterface => str_contains($url, self::LISTING) ? new MockResponse('[]') : new MockResponse('{}', ['http_code' => 201]));
        $verdict = new PublishedVerdict(new CommitId(self::HEAD), HeadStatus::Pending, 'The description.', 'The verdict.', new ReviewToken(self::TOKEN), new CommentBodies('- <code>bin/a&#95;b&#60;c&#62;</code>'));

        self::assertTrue($forge->publishVerdict('widgets', new ChangeId(self::PULL), $verdict));

        self::assertSame([self::POST_COMMENT, self::POST_COMMENT, self::POST_STATUS], $this->posts);
    }

    public function testAnOwnerAskThatHoldsASecretPostsNothing(): void
    {
        $forge = $this->forge(static fn (string $url): ResponseInterface => str_contains($url, self::LISTING) ? new MockResponse('[]') : new MockResponse('{}', ['http_code' => 201]));
        $verdict = new PublishedVerdict(new CommitId(self::HEAD), HeadStatus::Pending, 'The description.', 'The verdict.', new ReviewToken(self::TOKEN), new CommentBodies("The fixture holds a key:\nsk_live_".str_repeat('4eC39HqLyjWDarjtT1zdp7dc', 2)));

        try {
            $forge->publishVerdict('widgets', new ChangeId(self::PULL), $verdict);
            self::fail('An ask that holds a secret must not be posted.');
        } catch (ReviewTextHoldsSecret) {
        }

        self::assertSame([], $this->posts);
    }

    private function askingVerdict(): PublishedVerdict
    {
        return new PublishedVerdict(new CommitId(self::HEAD), HeadStatus::Pending, 'The description.', 'The verdict.', new ReviewToken(self::TOKEN), new CommentBodies('Ask, part 1.', 'Ask, part 2.'));
    }

    /**
     * @param list<string> $asks
     */
    private function askMarker(int $part, array $asks = ['Ask, part 1.', 'Ask, part 2.']): string
    {
        return '<!-- intakt-owner-ask: '.self::TOKEN.' '.hash('sha256', json_encode($asks, JSON_THROW_ON_ERROR)).' part '.$part.' -->';
    }

    /**
     * @param list<string> $bodies
     */
    private function forgeThatListsAlreadyPosted(array $bodies): GitHubForge
    {
        $posted = json_encode(array_map(static fn (string $body): array => ['body' => $body, 'performed_via_github_app' => ['id' => 12345]], $bodies), JSON_THROW_ON_ERROR);

        return $this->forge(static fn (string $url): ResponseInterface => str_contains($url, self::LISTING) ? new MockResponse($posted) : new MockResponse('{}', ['http_code' => 201]));
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
        $http = new RetryableHttpClient(new MockHttpClient(function (string $method, string $url, array $options) use ($answer): ResponseInterface {
            if ('POST' === $method) {
                $this->posts[] = $method.' '.$url;
                $this->bodies[] = $this->commentBodyOf($options);
            }

            return $answer($url);
        }, 'https://api.github.com'), new GenericRetryStrategy(delayMs: 0), 2);

        $calls = new GitHubCalls($http, $readings, $tokens);
        $checked = new CheckedInstallationTokens($readBack, $tokens);

        return new GitHubForge($calls, $checked, new SecretScan(dirname(__DIR__, 3), 'gitleaks'), new PostedComments($calls, $checked, '12345'), new VerdictLocks(new LockFactory(new InMemoryStore())), new GitHubAppSetting('12345', new NullLogger()));
    }

    private function publish(GitHubForge $forge, ?string $comment): void
    {
        $status = null === $comment ? HeadStatus::Error : HeadStatus::Success;
        self::assertTrue($forge->publishVerdict('widgets', new ChangeId(self::PULL), new PublishedVerdict(new CommitId(self::HEAD), $status, 'The description.', $comment, new ReviewToken(self::TOKEN))));
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
     * @param array<mixed> $options
     */
    private function commentBodyOf(array $options): string
    {
        $sent = is_string($options['body'] ?? null) ? json_decode($options['body'], true) : null;

        return is_array($sent) && is_string($sent['body'] ?? null) ? $sent['body'] : '';
    }
}
