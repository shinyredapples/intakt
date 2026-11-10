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

    public function testItFindsTheMarkerOnALaterPage(): void
    {
        $pages = [1 => $this->others(100), 2 => [['body' => "The verdict.\n\n".self::MARKER]]];

        self::assertTrue($this->comments($this->listing($pages))->holdsMarker($this->address(), self::MARKER));
    }

    public function testItStopsAtTheFirstShortPageWithoutTheMarker(): void
    {
        $asked = [];
        $comments = $this->comments(function (string $method, string $url) use (&$asked): ResponseInterface {
            $asked[] = $url;

            return new MockResponse((string) json_encode($this->others(3)), ['http_code' => 200]);
        });

        self::assertFalse($comments->holdsMarker($this->address(), self::MARKER));
        self::assertSame(['https://api.github.com/repos/acme/widget/issues/7/comments?per_page=100&page=1'], $asked);
    }

    public function testItReadsThirtyPagesWhenTheLastOneIsShort(): void
    {
        $pages = array_fill(1, 29, $this->others(100)) + [30 => $this->others(99)];

        self::assertFalse($this->comments($this->listing($pages))->holdsMarker($this->address(), self::MARKER));
    }

    public function testItRefusesThePostWhenThirtyPagesAreFull(): void
    {
        $this->expectExceptionObject(new ForgeWriteFailed('The pull request holds more comments than this forge reads before a post.'));

        (void) $this->comments($this->listing(array_fill(1, 31, $this->others(100))))->holdsMarker($this->address(), self::MARKER);
    }

    public function testARefusedReadFailsThePostAndSaysTheCredentialsWereRefused(): void
    {
        try {
            (void) $this->comments(new MockResponse('{"message":"Bad credentials"}', ['http_code' => 401]))->holdsMarker($this->address(), self::MARKER);
            self::fail('A refused read must fail the post.');
        } catch (ForgeWriteFailed $failed) {
            self::assertSame('GitHub answered 401 to a read of the comments before a post.', $failed->getMessage());
            self::assertInstanceOf(ForgeCredentialsRefused::class, $failed->getPrevious());
        }
    }

    public function testAnAnswerThatIsNotAListFailsThePost(): void
    {
        $this->expectExceptionObject(new ForgeWriteFailed('GitHub answered a comment listing that is not a list.'));

        (void) $this->comments(new MockResponse('{}', ['http_code' => 200]))->holdsMarker($this->address(), self::MARKER);
    }

    public function testAnAnswerThatIsNotJsonFailsThePost(): void
    {
        $this->expectException(ForgeWriteFailed::class);
        $this->expectExceptionMessage('GitHub listed the comments as something other than JSON.');

        (void) $this->comments(new MockResponse('<html>', ['http_code' => 200]))->holdsMarker($this->address(), self::MARKER);
    }

    /**
     * @param array<int, list<array<string, string>>> $pages
     */
    private function listing(array $pages): callable
    {
        return static function (string $method, string $url) use ($pages): ResponseInterface {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $page = is_numeric($query['page'] ?? null) ? (int) $query['page'] : 0;

            return new MockResponse((string) json_encode($pages[$page] ?? []), ['http_code' => 200]);
        };
    }

    /**
     * @return list<array<string, string>>
     */
    private function others(int $count): array
    {
        return array_fill(0, $count, ['body' => 'Another reviewer\'s comment.']);
    }

    private function address(): PullRequestAddress
    {
        return PullRequestAddress::of(new ChangeId('https://github.com/acme/widget/pull/7'));
    }

    private function comments(callable|MockResponse $answer): PostedComments
    {
        $clock = new MockClock('2026-11-25 10:00:00 UTC');
        $identity = new RecordedInstallation('installation-secret')->client();
        $jwt = new AppJwt('12345', AppKey::generate()->private, $clock);
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, $identity, new NullLogger()), '678', $clock);
        $expected = ExpectedInstallation::fromSettings('contents:write,pull_requests:write,workflows:write', 'acme/widget,acme/gadget');
        $readings = FreshReadings::at($clock);
        $readBack = new InstallationReadBack(new InstallationReader($jwt, $tokens, new GitHubCalls($identity, $readings, $tokens)), $expected, new NullLogger());

        return new PostedComments(new GitHubCalls(new MockHttpClient($answer, 'https://api.github.com'), $readings, $tokens), new CheckedInstallationTokens($readBack, $tokens));
    }
}
