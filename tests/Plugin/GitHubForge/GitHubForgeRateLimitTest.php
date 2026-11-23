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
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\Forge\Contract\JobCredentials;
use Intakt\Plugin\Forge\Contract\ReviewComment;
use Intakt\Plugin\Forge\Contract\ReviewToken;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
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
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(GitHubForge::class)]
#[CoversClass(RateLimitReadings::class)]
#[CoversClass(GitHubCalls::class)]
#[UsesClass(RateLimitReading::class)]
#[UsesClass(ForgeRateLimited::class)]
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
#[UsesClass(InstallationToken::class)]
#[UsesClass(InstallationTokenMinter::class)]
#[UsesClass(InstallationTokens::class)]
#[UsesClass(AppJwt::class)]
#[UsesClass(CheckedInstallationTokens::class)]
#[UsesClass(InstallationReadBack::class)]
#[UsesClass(InstallationReader::class)]
#[UsesClass(ExpectedInstallation::class)]
#[UsesClass(Installation::class)]
final class GitHubForgeRateLimitTest extends TestCase
{
    private const string PULL = 'https://github.com/acme/widgets/pull/7';
    private const string HEAD = '1111111111111111111111111111111111111111';
    private const string MERGE_BASE = '3333333333333333333333333333333333333333';

    /** @var list<string> */
    private array $requested = [];
    private MockClock $clock;
    private RateLimitReadings $readings;
    private bool $limited = true;

    public function testItRaisesTheResetOfAPrimaryLimitAnswered403(): void
    {
        $reset = $this->clock->now()->getTimestamp() + 1200;
        $forge = $this->forgeAnswering(fn (): MockResponse => $this->limited(403, ['x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => (string) $reset]));

        $limited = $this->rateLimitOf(static fn () => $forge->change(new ChangeId(self::PULL)));

        self::assertSame($reset, $limited->resetsAt->getTimestamp());
        self::assertCount(1, $this->requested);
    }

    public function testItMakesNoCallBeforeTheResetOfARateLimit(): void
    {
        $reset = $this->clock->now()->getTimestamp() + 1800;
        $forge = $this->forgeAnswering(fn (): MockResponse => $this->limited(403, ['x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => (string) $reset]));
        $this->rateLimitOf(static fn () => $forge->change(new ChangeId(self::PULL)));

        $this->clock->modify('+10 minutes');
        $again = $this->rateLimitOf(static fn () => $forge->change(new ChangeId(self::PULL)));

        self::assertSame($reset, $again->resetsAt->getTimestamp());
        self::assertCount(1, $this->requested);
    }

    public function testItCallsGitHubAgainOnceTheResetHasPassed(): void
    {
        $forge = $this->forgeAnswering(fn (): ?MockResponse => $this->limited ? $this->limited(429, ['retry-after' => '30']) : null);
        $this->rateLimitOf(static fn () => $forge->change(new ChangeId(self::PULL)));
        $this->limited = false;
        $this->clock->modify('+31 seconds');

        self::assertSame(self::HEAD, $forge->change(new ChangeId(self::PULL))->commits->head->hash);
    }

    public function testItRaisesTheRetryAfterOfA429AndNeverRetriesIt(): void
    {
        $forge = $this->forgeAnswering(fn (): MockResponse => $this->limited(429, ['retry-after' => '45']));

        $limited = $this->rateLimitOf(static fn () => $forge->change(new ChangeId(self::PULL)));

        self::assertSame($this->clock->now()->getTimestamp() + 45, $limited->resetsAt->getTimestamp());
        self::assertCount(1, $this->requested);
    }

    public function testItTreatsA403WithRetryAfterAsARateLimit(): void
    {
        $forge = $this->forgeAnswering(fn (): MockResponse => $this->limited(403, ['retry-after' => '60']));

        $limited = $this->rateLimitOf(static fn () => $forge->change(new ChangeId(self::PULL)));

        self::assertSame($this->clock->now()->getTimestamp() + 60, $limited->resetsAt->getTimestamp());
    }

    public function testItKeepsA403WithoutRateLimitHeadersAnOrdinaryRefusal(): void
    {
        $forge = $this->forgeAnswering(fn (): MockResponse => $this->limited(403, ['x-ratelimit-remaining' => '4999', 'x-ratelimit-reset' => '1795601400']));

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered 403 to a read.'));

        $forge->change(new ChangeId(self::PULL));
    }

    public function testItRaisesTheRateLimitOfAWriteAndDoesNotRetryIt(): void
    {
        $forge = $this->forgeAnswering(fn (): ?MockResponse => $this->limited ? $this->limited(403, ['retry-after' => '20']) : null);
        $this->limited = false;
        $change = $forge->change(new ChangeId(self::PULL));
        $this->limited = true;
        $this->requested = [];

        $limited = $this->rateLimitOf(static fn () => $forge->postReview('widgets', $change, new ReviewComment('Two reviewers approve.', new ReviewToken('round-7-token-0123456789'), new JobCredentials())));

        self::assertSame($this->clock->now()->getTimestamp() + 20, $limited->resetsAt->getTimestamp());
        self::assertCount(1, $this->requested);
    }

    public function testItPostsAReviewWithTheHourlyBudgetBelowTheReserve(): void
    {
        $this->limited = false;
        $forge = $this->forgeAnswering(static fn (): ?MockResponse => null);
        $change = $forge->change(new ChangeId(self::PULL));
        $this->readings->observe('678', 200, ['x-ratelimit-remaining' => ['3'], 'x-ratelimit-limit' => ['5000'], 'x-ratelimit-reset' => [(string) ($this->clock->now()->getTimestamp() + 1800)]]);
        $this->requested = [];

        $forge->postReview('widgets', $change, new ReviewComment('Two reviewers approve.', new ReviewToken('round-7-token-0123456789'), new JobCredentials()));

        self::assertSame(['GET https://api.github.com/repos/acme/widgets/pulls/7', 'POST https://api.github.com/repos/acme/widgets/pulls/7/reviews'], $this->requested);
    }

    public function testItStillRetriesADroppedConnectionOfARead(): void
    {
        $this->limited = false;
        $dropped = false;
        $forge = $this->forgeAnswering(static function () use (&$dropped): ?MockResponse {
            if ($dropped) {
                return null;
            }
            $dropped = true;

            return new MockResponse('', ['error' => 'connection dropped']);
        });

        self::assertSame(self::HEAD, $forge->change(new ChangeId(self::PULL))->commits->head->hash);
        self::assertSame($this->requested[0], $this->requested[1]);
    }

    public function testItRecordsTheBudgetOfEveryAnswerForTheInstallation(): void
    {
        $reset = $this->clock->now()->getTimestamp() + 900;
        $this->limited = false;
        $forge = $this->forgeAnswering(static fn (): MockResponse => new MockResponse(self::pull(), ['response_headers' => ['x-ratelimit-remaining' => '4321', 'x-ratelimit-limit' => '5000', 'x-ratelimit-reset' => (string) $reset]]));

        $change = new Change(new ChangeId(self::PULL), new CommitRange(new CommitId(self::MERGE_BASE), new CommitId(self::HEAD)), new Diff('', new ChangedFiles()));

        self::assertFalse($forge->hasHeadMoved($change));

        $reading = $this->readings->latest('678');
        self::assertSame(4321, $reading?->remaining);
        self::assertSame($reset, $reading->resetsAt->getTimestamp());
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-25 10:00:00 UTC');
        $this->readings = FreshReadings::at($this->clock);
    }

    /**
     * @param array<string, string> $headers
     */
    private function limited(int $status, array $headers): MockResponse
    {
        return new MockResponse('{"message":"rate limit"}', ['http_code' => $status, 'response_headers' => $headers]);
    }

    /**
     * @param Closure(): ?MockResponse $answer
     */
    private function forgeAnswering(Closure $answer): GitHubForge
    {
        $client = new MockHttpClient(function (string $method, string $url) use ($answer): ResponseInterface {
            $this->requested[] = $method.' '.$url;

            return $answer() ?? $this->plain($url);
        }, 'https://api.github.com');
        // The retry codes come from the shipped config, so the test fails if a 429 were retried there.
        $http = new RetryableHttpClient($client, new GenericRetryStrategy($this->configuredRetryCodes(), delayMs: 0), 2);
        $identity = new RecordedInstallation('installation-secret')->client();
        $jwt = new AppJwt('12345', AppKey::generate()->private, $this->clock);
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, $identity, new NullLogger()), '678', $this->clock);
        $expected = ExpectedInstallation::fromSettings('contents:write,pull_requests:write,workflows:write', 'acme/widget,acme/gadget');
        $readBack = new InstallationReadBack(new InstallationReader($jwt, $tokens, new GitHubCalls($identity, $this->readings, $tokens)), $expected, new NullLogger());

        $calls = new GitHubCalls($http, $this->readings, $tokens);
        $checked = new CheckedInstallationTokens($readBack, $tokens);

        return new GitHubForge($calls, $checked, new SecretScan(dirname(__DIR__, 3), 'gitleaks'), new PostedComments($calls, $checked, '12345'), new VerdictLocks(new LockFactory(new InMemoryStore())), new GitHubAppSetting('12345', new NullLogger()));
    }

    private function rateLimitOf(Closure $call): ForgeRateLimited
    {
        try {
            $call();
        } catch (ForgeRateLimited $limited) {
            return $limited;
        }

        throw new LogicException('The call was expected to be rate limited.');
    }

    private function plain(string $url): MockResponse
    {
        return match (true) {
            str_ends_with($url, '/reviews') => new MockResponse(self::fixture('review_posted.json')),
            str_contains($url, '/pulls/7') => new MockResponse(self::pull()),
            default => new MockResponse(self::fixture('compare_merge_base.json')),
        };
    }

    private static function pull(): string
    {
        return self::fixture('pull_request.json');
    }

    /**
     * @return array<int, int|list<string>>
     */
    private function configuredRetryCodes(): array
    {
        /** @var array{framework: array{http_client: array{scoped_clients: array{'github.client': array{retry_failed: array{http_codes: array<int, true|list<string>>}}}}}} $config */
        $config = Yaml::parseFile(dirname(__DIR__, 3).'/config/packages/framework.yaml');

        // Built as FrameworkBundle builds the strategy: a code with methods keeps them, any other is retried for every method.
        $codes = [];
        foreach ($config['framework']['http_client']['scoped_clients']['github.client']['retry_failed']['http_codes'] as $code => $methods) {
            if (is_array($methods)) {
                $codes[$code] = $methods;

                continue;
            }
            $codes[] = $code;
        }

        return $codes;
    }

    private static function fixture(string $name): string
    {
        $content = file_get_contents(__DIR__.'/Fixtures/'.$name);

        return false === $content ? throw new LogicException('Missing fixture '.$name) : $content;
    }
}
