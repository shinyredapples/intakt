<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ForgeCredentialsRefused;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\CheckedInstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\PostedComments;
use Intakt\Plugin\GitHubForge\Infrastructure\PullRequestAddress;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(PostedComments::class)]
final class PostedCommentsTest extends TestCase
{
    private const string MARKER = '<!-- intakt-verdict: round-of-the-test -->';
    private const string COMMENT = 'The verdict.';
    private const int APP_ID = 12345;

    public function testACommentWhoseMarkerIsOnALaterPageIsNotPostedAgain(): void
    {
        $pages = [1 => $this->others(100), 2 => [$this->postedBy(self::APP_ID)]];

        self::assertSame([], $this->toPost($this->comments($this->listing($pages))));
    }

    public function testAMarkerInAPersonsCommentDoesNotStopTheAppsComment(): void
    {
        $foreign = ['body' => self::MARKER, 'user' => ['login' => 'outsider', 'type' => 'User'], 'performed_via_github_app' => null];

        self::assertSame([self::COMMENT], $this->toPost($this->comments($this->listing([1 => [$foreign]]))));
    }

    public function testAMarkerInAnotherAppsCommentDoesNotStopTheAppsComment(): void
    {
        self::assertSame([self::COMMENT], $this->toPost($this->comments($this->listing([1 => [$this->postedBy(54321)]]))));
    }

    public function testTheAppsCommentOfAnotherRoundQuotingThisRoundsMarkerInAFindingDoesNotStopThePost(): void
    {
        $otherRound = ['body' => '[P2] src/A.php: it quotes '.self::MARKER." here\n\n<!-- intakt-verdict: another-round -->", 'performed_via_github_app' => ['id' => self::APP_ID]];

        self::assertSame([self::COMMENT], $this->toPost($this->comments($this->listing([1 => [$otherRound]]))));
    }

    public function testTheAppsCommentWhoseMarkerIsFollowedByTrailingWhitespaceIsNotPostedAgain(): void
    {
        $posted = ['body' => self::COMMENT."\n\n".self::MARKER."\n", 'performed_via_github_app' => ['id' => self::APP_ID]];

        self::assertSame([], $this->toPost($this->comments($this->listing([1 => [$posted]]))));
    }

    public function testNoCommentToPostReadsNothing(): void
    {
        $comments = $this->comments(static fn (): ResponseInterface => throw new LogicException('No comment, no read.'));

        self::assertSame([], $comments->notYetPosted($this->address(), self::MARKER, []));
    }

    public function testItStopsAtTheFirstShortPageWithoutTheMarker(): void
    {
        $asked = [];
        $comments = $this->comments(function (string $method, string $url) use (&$asked): ResponseInterface {
            $asked[] = $url;

            return new MockResponse((string) json_encode($this->others(3)), ['http_code' => 200]);
        });

        self::assertSame([self::COMMENT], $this->toPost($comments));
        self::assertSame(['https://api.github.com/repos/acme/widget/issues/7/comments?per_page=100&page=1'], $asked);
    }

    public function testItReadsThirtyPagesWhenTheLastOneIsShort(): void
    {
        $pages = array_fill(1, 29, $this->others(100)) + [30 => $this->others(99)];

        self::assertSame([self::COMMENT], $this->toPost($this->comments($this->listing($pages))));
    }

    public function testItRefusesThePostWhenThirtyPagesAreFull(): void
    {
        $this->expectExceptionObject(new ForgeWriteFailed('The pull request holds more comments than this forge reads before a post.'));

        $this->toPost($this->comments($this->listing(array_fill(1, 31, $this->others(100)))));
    }

    public function testARefusedReadFailsThePostAndSaysTheCredentialsWereRefused(): void
    {
        try {
            $this->toPost($this->comments(new MockResponse('{"message":"Bad credentials"}', ['http_code' => 401])));
            self::fail('A refused read must fail the post.');
        } catch (ForgeWriteFailed $failed) {
            self::assertSame('GitHub answered 401 to a read of the comments before a post.', $failed->getMessage());
            self::assertInstanceOf(ForgeCredentialsRefused::class, $failed->getPrevious());
        }
    }

    public function testAnAnswerThatIsNotAListFailsThePost(): void
    {
        $this->expectExceptionObject(new ForgeWriteFailed('GitHub answered a comment listing that is not a list.'));

        $this->toPost($this->comments(new MockResponse('{}', ['http_code' => 200])));
    }

    public function testAnAnswerThatIsNotJsonFailsThePost(): void
    {
        $this->expectExceptionObject(new ForgeWriteFailed('GitHub listed the comments as something other than JSON.', JSON_ERROR_SYNTAX));

        $this->toPost($this->comments(new MockResponse('<html>', ['http_code' => 200])));
    }

    /**
     * @return list<array<string, string>>
     */
    private function others(int $count): array
    {
        return array_fill(0, $count, ['body' => 'Another reviewer\'s comment.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function postedBy(int $app): array
    {
        return ['body' => self::COMMENT."\n\n".self::MARKER, 'performed_via_github_app' => ['id' => $app]];
    }

    /**
     * @param array<int, list<array<string, mixed>>> $pages
     */
    private function listing(array $pages): callable
    {
        return static function (string $method, string $url) use ($pages): ResponseInterface {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $page = is_numeric($query['page'] ?? null) ? (int) $query['page'] : 0;

            return new MockResponse((string) json_encode($pages[$page] ?? []), ['http_code' => 200]);
        };
    }

    private function address(): PullRequestAddress
    {
        return PullRequestAddress::of(new ChangeId('https://github.com/acme/widget/pull/7'));
    }

    private function comments(callable|MockResponse $answer): PostedComments
    {
        $clock = new MockClock('2026-11-25 10:00:00 UTC');
        $identity = new RecordedInstallation('installation-secret')->client();
        $jwt = new AppJwt((string) self::APP_ID, AppKey::generate()->private, $clock);
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, $identity, new NullLogger()), '678', $clock);
        $expected = ExpectedInstallation::fromSettings('contents:write,pull_requests:write,workflows:write', 'acme/widget,acme/gadget');
        $readings = FreshReadings::at($clock);
        $readBack = new InstallationReadBack(new InstallationReader($jwt, $tokens, new GitHubCalls($identity, $readings, $tokens)), $expected, new NullLogger());

        return new PostedComments(new GitHubCalls(new MockHttpClient($answer, 'https://api.github.com'), $readings, $tokens), new CheckedInstallationTokens($readBack, $tokens), (string) self::APP_ID);
    }

    /**
     * @return list<string>
     */
    private function toPost(PostedComments $comments): array
    {
        return $comments->notYetPosted($this->address(), self::MARKER, [self::COMMENT]);
    }
}
