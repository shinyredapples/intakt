<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Closure;
use Intakt\Plugin\Forge\Contract\Change;
use Intakt\Plugin\Forge\Contract\ChangedFile;
use Intakt\Plugin\Forge\Contract\ChangedFiles;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitRange;
use Intakt\Plugin\Forge\Contract\Diff;
use Intakt\Plugin\Forge\Contract\FileContent;
use Intakt\Plugin\Forge\Contract\FileNotFoundAtHead;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\HeadMove;
use Intakt\Plugin\Forge\Contract\HeadMoveKind;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubForge;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\PullRequestAddress;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(GitHubForge::class)]
#[CoversClass(PullRequestAddress::class)]
#[UsesClass(Change::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(ChangeNotFound::class)]
#[UsesClass(ChangedFile::class)]
#[UsesClass(ChangedFiles::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(CommitRange::class)]
#[UsesClass(Diff::class)]
#[UsesClass(FileContent::class)]
#[UsesClass(FileNotFoundAtHead::class)]
#[UsesClass(ForgeReadFailed::class)]
#[UsesClass(HeadMove::class)]
#[UsesClass(InstallationToken::class)]
#[UsesClass(InstallationTokenNotMinted::class)]
#[UsesClass(InstallationTokenMinter::class)]
#[UsesClass(InstallationTokens::class)]
#[UsesClass(AppJwt::class)]
final class GitHubForgeTest extends TestCase
{
    private const string PULL = 'https://github.com/acme/widgets/pull/7';
    private const string HEAD = '1111111111111111111111111111111111111111';
    private const string MERGE_BASE = '3333333333333333333333333333333333333333';
    private const string API = 'https://api.github.com/repos/acme/widgets';

    /** @var list<string> */
    private array $requested = [];
    private string $pullFixture = 'pull_request.json';
    private string $compareFixture = 'compare_merge_base.json';
    private string $minted = 'installation-secret';

    public function testItReadsTheHeadBaseDiffAndChangedFilesOfAPullRequest(): void
    {
        $change = $this->forge()->change(new ChangeId(self::PULL));

        self::assertSame(self::HEAD, $change->commits->head->hash);
        self::assertSame(self::MERGE_BASE, $change->commits->base->hash);
        self::assertSame(self::fixture('pull.diff'), $change->diff->patch);
        self::assertSame(2, $change->diff->addedLines());
        self::assertSame(1, $change->diff->removedLines());
    }

    public function testItSplitsARenamedFileIntoADeletionAndAnAddition(): void
    {
        $change = $this->forge()->change(new ChangeId(self::PULL));

        self::assertSame(
            ['src/Widget.php:modified', 'src/Added.php:added', 'src/Gone.php:deleted', 'src/Old.php:deleted', 'src/Moved.php:added'],
            array_map(static fn (ChangedFile $file): string => $file->path.':'.$file->status->value, [...$change->diff->files]),
        );
    }

    public function testItReadsEveryPageOfChangedFiles(): void
    {
        $page = json_encode(array_map(static fn (int $number): array => ['filename' => 'src/F'.$number.'.php', 'status' => 'added'], range(1, 100)), JSON_THROW_ON_ERROR);
        $last = json_encode([['filename' => 'src/Last.php', 'status' => 'added']], JSON_THROW_ON_ERROR);
        $forge = $this->forge(static fn (string $url): ?ResponseInterface => str_contains($url, '/files?') ? new MockResponse(str_contains($url, 'page=2') ? $last : $page) : null);

        $change = $forge->change(new ChangeId(self::PULL));

        self::assertCount(101, $change->diff->files);
    }

    public function testItDetectsAHeadThatMovedDuringReview(): void
    {
        $forge = $this->forge();
        $change = $forge->change(new ChangeId(self::PULL));
        $this->pullFixture = 'pull_request_moved.json';

        self::assertTrue($forge->hasHeadMoved($change));
    }

    public function testItReportsAnUnmovedHeadWhenTheShaIsUnchanged(): void
    {
        $forge = $this->forge();
        $change = $forge->change(new ChangeId(self::PULL));

        self::assertFalse($forge->hasHeadMoved($change));
        self::assertSame(HeadMoveKind::Unmoved, $forge->headMoveSince(new CommitId(self::HEAD), $change)->kind);
    }

    public function testItReportsNewCommitsWithTheirPathsWhenTheHeadMovedForward(): void
    {
        $forge = $this->forge();
        $change = $this->movedChange($forge);
        $this->compareFixture = 'compare_ahead.json';

        $move = $forge->headMoveSince(new CommitId(self::HEAD), $change);

        self::assertSame(HeadMoveKind::Commits, $move->kind);
        self::assertSame(['src/Added.php', 'docs/read me.md'], $move->paths);
    }

    public function testItReportsARewriteWhenTheEarlierHeadIsNoLongerAnAncestor(): void
    {
        $forge = $this->forge();
        $change = $this->movedChange($forge);
        $this->compareFixture = 'compare_diverged.json';

        self::assertSame(HeadMoveKind::Rewritten, $forge->headMoveSince(new CommitId(self::HEAD), $change)->kind);
    }

    public function testItReportsARewriteWhenGitHubNoLongerKnowsTheEarlierHead(): void
    {
        $forge = $this->forge(static fn (string $url): ?ResponseInterface => str_contains($url, '/compare/'.self::HEAD.'...') ? new MockResponse(self::fixture('not_found.json'), ['http_code' => 404]) : null);
        $change = $this->movedChange($forge);

        self::assertSame(HeadMoveKind::Rewritten, $forge->headMoveSince(new CommitId(self::HEAD), $change)->kind);
    }

    public function testItReturnsTheFileContentAtTheHeadCommit(): void
    {
        $forge = $this->forge();
        $change = $forge->change(new ChangeId(self::PULL));

        $file = $forge->fileAtHead($change, 'src/My Dir/Widget.php');

        self::assertSame(self::fixture('contents_widget.txt'), $file->content);
        self::assertContains('GET '.self::API.'/contents/src/My%20Dir/Widget.php?ref='.self::HEAD, $this->requested);
    }

    public function testItRefusesAFileMissingAtHeadAsFileNotFoundAtHead(): void
    {
        $forge = $this->forge(static fn (string $url): ?ResponseInterface => str_contains($url, '/contents/') ? new MockResponse(self::fixture('not_found.json'), ['http_code' => 404]) : null);
        $change = $forge->change(new ChangeId(self::PULL));

        $this->expectException(FileNotFoundAtHead::class);

        $forge->fileAtHead($change, 'src/Missing.php');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedAddresses(): iterable
    {
        yield 'not a url' => ['not a url'];
        yield 'another host' => ['https://evil.example/acme/widgets/pull/7'];
        yield 'plain http' => ['http://github.com/acme/widgets/pull/7'];
        yield 'credentials' => ['https://user:pass@github.com/acme/widgets/pull/7'];
        yield 'an issue' => ['https://github.com/acme/widgets/issues/7'];
        yield 'a branch name' => ['feature/read'];
    }

    #[DataProvider('malformedAddresses')]
    public function testItRefusesAMalformedPullRequestUrlAsChangeNotFound(string $address): void
    {
        $this->expectException(ChangeNotFound::class);

        $this->forge()->change(new ChangeId($address));
    }

    public function testItTreatsAMissingPullRequestAsChangeNotFound(): void
    {
        $forge = $this->forge(static fn (): ResponseInterface => new MockResponse(self::fixture('not_found.json'), ['http_code' => 404]));

        $this->expectException(ChangeNotFound::class);

        $forge->change(new ChangeId(self::PULL));
    }

    public function testItTurnsAnErrorStatusIntoAForgeReadFailure(): void
    {
        $forge = $this->forge(static fn (): ResponseInterface => new MockResponse('{}', ['http_code' => 403]));

        $this->expectException(ForgeReadFailed::class);
        $this->expectExceptionMessage('403');

        $forge->change(new ChangeId(self::PULL));
    }

    public function testItRetriesAFailedReadThroughTheRetryableClient(): void
    {
        $failures = 1;
        $http = new RetryableHttpClient(
            new MockHttpClient(function (string $method, string $url, array $options) use (&$failures): ResponseInterface {
                if ($failures > 0 && str_ends_with($url, '/pulls/7')) {
                    --$failures;

                    return new MockResponse('', ['http_code' => 502]);
                }

                return $this->answer($url, $options);
            }, 'https://api.github.com'),
            new GenericRetryStrategy(delayMs: 0),
            2,
        );

        $change = $this->forgeOver($http)->change(new ChangeId(self::PULL));

        self::assertSame(self::HEAD, $change->commits->head->hash);
        self::assertSame(0, $failures);
    }

    public function testItSendsTheInstallationTokenAsBearer(): void
    {
        $bearers = [];
        $forge = $this->forge(static function (string $url, array $options) use (&$bearers): ?ResponseInterface {
            $bearers[] = $options['normalized_headers']['authorization'][0] ?? null;

            return null;
        });

        $forge->change(new ChangeId(self::PULL));

        self::assertSame(['Authorization: Bearer installation-secret'], array_values(array_unique($bearers)));
    }

    public function testItTurnsAnUnmintableTokenIntoAForgeReadFailure(): void
    {
        $tokens = new InstallationTokens(
            new InstallationTokenMinter(new AppJwt('12345', AppKey::generate()->private, new MockClock('2026-11-25 10:00:00 UTC')), new MockHttpClient(new MockResponse('{}', ['http_code' => 401])), new NullLogger()),
            '678',
            new MockClock('2026-11-25 10:00:00 UTC'),
        );
        $forge = new GitHubForge(new MockHttpClient(), $tokens);

        $this->expectException(ForgeReadFailed::class);

        $forge->change(new ChangeId(self::PULL));
    }

    private function movedChange(GitHubForge $forge): Change
    {
        $this->pullFixture = 'pull_request_moved.json';
        $this->compareFixture = 'compare_merge_base.json';
        $change = $forge->change(new ChangeId(self::PULL));
        $this->compareFixture = 'compare_ahead.json';

        return $change;
    }

    /**
     * @param ?Closure(string, array<mixed>): ?ResponseInterface $override
     */
    private function forge(?Closure $override = null): GitHubForge
    {
        return $this->forgeOver(new MockHttpClient(function (string $method, string $url, array $options) use ($override): ResponseInterface {
            $this->requested[] = $method.' '.$url;

            return $override?->__invoke($url, $options) ?? $this->answer($url, $options);
        }, 'https://api.github.com'));
    }

    private function forgeOver(HttpClientInterface $http): GitHubForge
    {
        $clock = new MockClock('2026-11-25 10:00:00 UTC');
        $minting = new MockHttpClient(fn (): ResponseInterface => new MockResponse(
            json_encode(['token' => $this->minted, 'expires_at' => '2026-11-25T11:00:00Z'], JSON_THROW_ON_ERROR),
            ['http_code' => 201],
        ));
        $tokens = new InstallationTokens(new InstallationTokenMinter(new AppJwt('12345', AppKey::generate()->private, $clock), $minting, new NullLogger()), '678', $clock);

        return new GitHubForge($http, $tokens);
    }

    /**
     * @param array<mixed> $options
     */
    private function answer(string $url, array $options): ResponseInterface
    {
        $accept = $options['normalized_headers']['accept'][0] ?? '';
        $path = explode('?', $url)[0];

        return match (true) {
            str_contains($url, '/contents/') => new MockResponse(self::fixture('contents_widget.txt')),
            str_contains($path, '/pulls/7/files') => new MockResponse(self::fixture('pull_files.json')),
            str_contains($path, '/pulls/7') => new MockResponse(self::fixture($this->pullFixture)),
            str_ends_with($accept, 'github.diff') => new MockResponse(self::fixture('pull.diff')),
            default => new MockResponse(self::fixture($this->compareFixture)),
        };
    }

    private static function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/Fixtures/'.$name) ?: throw new LogicException('Missing fixture '.$name);
    }
}
