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
use Intakt\Plugin\Forge\Contract\CommitIdMalformed;
use Intakt\Plugin\Forge\Contract\CommitRange;
use Intakt\Plugin\Forge\Contract\Diff;
use Intakt\Plugin\Forge\Contract\FileContent;
use Intakt\Plugin\Forge\Contract\FileNotFoundAtHead;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\Forge\Contract\HeadMove;
use Intakt\Plugin\Forge\Contract\HeadMoveKind;
use Intakt\Plugin\Forge\Contract\JobCredentials;
use Intakt\Plugin\Forge\Contract\ReviewComment;
use Intakt\Plugin\Forge\Contract\ReviewTextHoldsSecret;
use Intakt\Plugin\Forge\Contract\ReviewToken;
use Intakt\Plugin\Forge\Contract\RoundStale;
use Intakt\Plugin\GitHubForge\Contract\InstallationDoesNotMatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
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
use Intakt\Tests\Plugin\Forge\CommitIdCheckThatThrows;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
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
#[UsesClass(CommitIdMalformed::class)]
#[UsesClass(CommitRange::class)]
#[UsesClass(Diff::class)]
#[UsesClass(FileContent::class)]
#[UsesClass(FileNotFoundAtHead::class)]
#[UsesClass(ForgeReadFailed::class)]
#[UsesClass(RateLimitReadings::class)]
#[UsesClass(ForgeWriteFailed::class)]
#[UsesClass(HeadMove::class)]
#[UsesClass(JobCredentials::class)]
#[UsesClass(ReviewComment::class)]
#[UsesClass(ReviewTextHoldsSecret::class)]
#[UsesClass(ReviewToken::class)]
#[UsesClass(SecretScan::class)]
#[UsesClass(RoundStale::class)]
#[UsesClass(InstallationToken::class)]
#[UsesClass(InstallationTokenNotMinted::class)]
#[UsesClass(InstallationTokenMinter::class)]
#[UsesClass(InstallationTokens::class)]
#[UsesClass(AppJwt::class)]
#[UsesClass(CheckedInstallationTokens::class)]
#[UsesClass(InstallationReadBack::class)]
#[UsesClass(InstallationReader::class)]
#[UsesClass(GitHubCalls::class)]
#[UsesClass(ExpectedInstallation::class)]
#[UsesClass(Installation::class)]
#[UsesClass(InstallationDoesNotMatchConfiguration::class)]
#[UsesClass(InstallationNotReadBack::class)]
final class GitHubForgeTest extends TestCase
{
    private const string PULL = 'https://github.com/acme/widgets/pull/7';
    private const string HEAD = '1111111111111111111111111111111111111111';
    private const string MERGE_BASE = '3333333333333333333333333333333333333333';
    private const string API = 'https://api.github.com/repos/acme/widgets';
    private const string TOKEN = 'round-7-token-0123456789';
    private const string POST_REVIEW = 'POST '.self::API.'/pulls/7/reviews';
    private const string MINTED = 'installation-secret';

    /** @var list<string> */
    private array $requested = [];
    /** @var array<string, array<mixed>> */
    private array $sent = [];
    private string $pullFixture = 'pull_request.json';
    private string $compareFixture = 'compare_merge_base.json';
    private string $expectedPermissions = 'contents:write,pull_requests:write,workflows:write';

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

    public function testItTakesTheChangedFilesFromTheComparisonThatNamedTheMergeBase(): void
    {
        $this->forge()->change(new ChangeId(self::PULL));

        self::assertSame([], array_filter($this->requested, static fn (string $request): bool => str_contains($request, '/files')));
    }

    public function testItFailsTheReadWhenTheComparisonListsThreeHundredFiles(): void
    {
        $forge = $this->forge(fn (string $url, array $options): ?ResponseInterface => $this->isBranchComparison($url, $options) ? new MockResponse(self::comparison(self::MERGE_BASE, 300)) : null);

        $this->expectException(ForgeReadFailed::class);

        $forge->change(new ChangeId(self::PULL));
    }

    public function testItReadsAComparisonOfTwoHundredNinetyNineFiles(): void
    {
        $forge = $this->forge(fn (string $url, array $options): ?ResponseInterface => $this->isBranchComparison($url, $options) ? new MockResponse(self::comparison(self::MERGE_BASE, 299)) : null);

        self::assertCount(299, $forge->change(new ChangeId(self::PULL))->diff->files);
    }

    public function testItFailsTheReadWhenTheComparisonHasNoFileList(): void
    {
        $answer = json_encode(['status' => 'ahead', 'merge_base_commit' => ['sha' => self::MERGE_BASE]], JSON_THROW_ON_ERROR);
        $forge = $this->forge(fn (string $url, array $options): ?ResponseInterface => $this->isBranchComparison($url, $options) ? new MockResponse($answer) : null);

        $this->expectException(ForgeReadFailed::class);

        $forge->change(new ChangeId(self::PULL));
    }

    public function testAnAbbreviatedHeadFromGitHubFailsTheReadAsBefore(): void
    {
        $this->pullFixture = 'pull_request_short_head.json';

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered something other than a commit hash.'));

        $this->forge()->change(new ChangeId(self::PULL));
    }

    #[RunInSeparateProcess]
    public function testAnUnexpectedInvalidArgumentWhileReadingAHashFromGitHubPassesThrough(): void
    {
        CommitIdCheckThatThrows::defineFor(str_repeat('1', 40));

        $this->expectExceptionObject(new InvalidArgumentException('unexpected'));

        $this->forge()->change(new ChangeId(self::PULL));
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

    public function testItReportsBothPathsOfAFileRenamedByTheNewCommits(): void
    {
        $forge = $this->forge();
        $change = $this->movedChange($forge);
        $this->compareFixture = 'compare_ahead_rename.json';

        $move = $forge->headMoveSince(new CommitId(self::HEAD), $change);

        self::assertSame(['src/Guard.php', 'docs/Guard.php'], $move->paths);
    }

    public function testItReportsARewriteWhenTheNewCommitsListThreeHundredFiles(): void
    {
        $forge = $this->forge();
        $change = $this->movedChange($forge);
        $forge = $this->forge(static fn (string $url): ?ResponseInterface => str_contains($url, '/compare/'.self::HEAD.'...') ? new MockResponse(self::comparison(self::HEAD, 300)) : null);

        self::assertSame(HeadMoveKind::Rewritten, $forge->headMoveSince(new CommitId(self::HEAD), $change)->kind);
    }

    public function testItReportsNewCommitsWhenTheyListTwoHundredNinetyNineFiles(): void
    {
        $forge = $this->forge();
        $change = $this->movedChange($forge);
        $forge = $this->forge(static fn (string $url): ?ResponseInterface => str_contains($url, '/compare/'.self::HEAD.'...') ? new MockResponse(self::comparison(self::HEAD, 299)) : null);

        $move = $forge->headMoveSince(new CommitId(self::HEAD), $change);

        self::assertSame(HeadMoveKind::Commits, $move->kind);
        self::assertCount(299, $move->paths);
    }

    public function testItReportsARewriteWhenTheNewCommitsHaveNoFileList(): void
    {
        $forge = $this->forge();
        $change = $this->movedChange($forge);
        $forge = $this->forge(static fn (string $url): ?ResponseInterface => str_contains($url, '/compare/'.self::HEAD.'...') ? new MockResponse('{"status":"ahead"}') : null);

        self::assertSame(HeadMoveKind::Rewritten, $forge->headMoveSince(new CommitId(self::HEAD), $change)->kind);
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
        self::assertNull($file->binaryBytes);
        self::assertContains('GET '.self::API.'/contents/src/My%20Dir/Widget.php?ref='.self::HEAD, $this->requested);
    }

    public function testAFileWhoseAnswerHoldsANulByteIsNamedBinaryByItsSize(): void
    {
        $forge = $this->forge(self::contentsAnswer(new MockResponse("\x89PNG\r\n\0 logo")));
        $change = $forge->change(new ChangeId(self::PULL));

        $file = $forge->fileAtHead($change, 'assets/logo.png');

        self::assertSame(12, $file->binaryBytes);
        self::assertSame('Binary file, not shown: 12 bytes', $file->content);
    }

    public function testItRefusesAFileMissingAtHeadAsFileNotFoundAtHead(): void
    {
        $forge = $this->forge(self::contentsAnswer(new MockResponse(self::fixture('not_found.json'), ['http_code' => 404])));
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
        yield 'dot segments for owner and repository' => ['https://github.com/../../pull/7'];
        yield 'a dot repository' => ['https://github.com/acme/./pull/7'];
        yield 'a dotted owner' => ['https://github.com/ac.me/widgets/pull/7'];
    }

    public function testItAcceptsARepositoryNameWithDots(): void
    {
        $this->forge()->change(new ChangeId('https://github.com/acme/widgets.js/pull/7'));

        self::assertContains('GET https://api.github.com/repos/acme/widgets.js/pulls/7', $this->requested);
    }

    public function testItDoesNotChainTheTransportFailureWhoseTraceHoldsTheToken(): void
    {
        $forge = $this->forgeOver(new MockHttpClient(static fn (): never => throw new TransportException('connection dropped'), 'https://api.github.com'));

        try {
            $forge->change(new ChangeId(self::PULL));
            self::fail('A transport failure must fail the read.');
        } catch (ForgeReadFailed $failure) {
            self::assertNull($failure->getPrevious());
            self::assertStringContainsString(TransportException::class, $failure->getMessage());
        }
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

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered 403 to a read.'));

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
            $bearers[] = self::header($options, 'authorization');

            return null;
        });

        $forge->change(new ChangeId(self::PULL));

        self::assertSame(['Authorization: Bearer installation-secret'], array_values(array_unique($bearers)));
    }

    public function testItTurnsAnUnmintableTokenIntoAForgeReadFailure(): void
    {
        $clock = new MockClock('2026-11-25 10:00:00 UTC');
        $jwt = new AppJwt('12345', AppKey::generate()->private, $clock);
        $identity = new RecordedInstallation(self::MINTED)->client();
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, $identity, new NullLogger()), '678', $clock);
        $expected = ExpectedInstallation::fromSettings($this->expectedPermissions, 'acme/widget,acme/gadget');
        $checked = new CheckedInstallationTokens(new InstallationReadBack(new InstallationReader($jwt, $tokens, new GitHubCalls($identity, FreshReadings::at($clock), $tokens)), $expected, new NullLogger()), $tokens);
        (void) $checked->current();
        // Past the recorded expiry, so the next mint answers with a token that already lapsed.
        $clock->modify('+2 hours');
        $calls = new GitHubCalls(new MockHttpClient(), FreshReadings::at($clock), $tokens);
        $forge = new GitHubForge($calls, $checked, $this->secretScan(), new PostedComments($calls, $checked, '12345'), new VerdictLocks(new LockFactory(new InMemoryStore())), new GitHubAppSetting('12345', new NullLogger()));

        try {
            $forge->change(new ChangeId(self::PULL));
            self::fail('A token that cannot be minted must refuse the read.');
        } catch (ForgeReadFailed $refused) {
            self::assertSame('No GitHub installation token for a read.', $refused->getMessage());
            self::assertInstanceOf(InstallationTokenNotMinted::class, $refused->getPrevious());
        }
    }

    public function testItReadsNothingFromAPullRequestWhenTheInstallationDoesNotMatch(): void
    {
        $this->expectedPermissions = 'contents:read,pull_requests:write,workflows:write';
        $forge = $this->forge();

        try {
            $forge->change(new ChangeId(self::PULL));
            self::fail('A mismatched installation must refuse the read.');
        } catch (ForgeReadFailed $refused) {
            self::assertStringContainsString('permission contents is expected at read but granted at write', $refused->getMessage());
        }

        self::assertSame([], $this->requested);
    }

    public function testItPostsNothingWhenTheInstallationDoesNotMatch(): void
    {
        $change = $this->forge()->change(new ChangeId(self::PULL));
        $this->expectedPermissions = 'contents:write';
        $this->requested = [];

        $failure = $this->failureOfPost($this->forge(), $change, $this->comment('Looks good.'));

        self::assertInstanceOf(ForgeWriteFailed::class, $failure);
        self::assertNotContains(self::POST_REVIEW, $this->requested);
    }

    public function testItPostsTheOutcomeAsOneCommentReviewCarryingTheRoundToken(): void
    {
        $forge = $this->forge();
        $change = $forge->change(new ChangeId(self::PULL));

        $forge->postReview('widgets', $change, new ReviewComment('Two reviewers approve.', new ReviewToken(self::TOKEN), new JobCredentials()));

        self::assertSame([
            'commit_id' => self::HEAD,
            'event' => 'COMMENT',
            'body' => "Two reviewers approve.\n\n<!-- intakt-review-token: ".self::TOKEN.' -->',
        ], json_decode($this->postedBody(), true, flags: JSON_THROW_ON_ERROR));
        self::assertCount(1, array_keys($this->requested, self::POST_REVIEW, true));
    }

    public function testItPostsNothingWhenTheHeadMovedBeforeThePost(): void
    {
        $forge = $this->forge();
        $change = $forge->change(new ChangeId(self::PULL));
        $this->pullFixture = 'pull_request_moved.json';

        $stale = $this->failureOfPost($forge, $change, $this->comment('Two reviewers approve.'));

        self::assertInstanceOf(RoundStale::class, $stale);
        self::assertStringContainsString(self::HEAD, $stale->getMessage());
        self::assertNotContains(self::POST_REVIEW, $this->requested);
        self::assertSame('GET '.self::API.'/pulls/7', array_last($this->requested));
    }

    public function testItSendsTheInstallationTokenAsBearerWithThePost(): void
    {
        $forge = $this->forge();

        $forge->postReview('widgets', $forge->change(new ChangeId(self::PULL)), new ReviewComment('Changes needed.', new ReviewToken(self::TOKEN), new JobCredentials()));

        self::assertSame('Authorization: Bearer installation-secret', self::header($this->sent[self::POST_REVIEW] ?? [], 'authorization'));
    }

    public function testItTurnsAnErrorStatusOfThePostIntoAForgeWriteFailure(): void
    {
        $forge = $this->forge(static fn (string $url): ?ResponseInterface => str_ends_with($url, '/reviews') ? new MockResponse('{"message":"Unprocessable Entity"}', ['http_code' => 422]) : null);
        $change = $forge->change(new ChangeId(self::PULL));

        $this->expectExceptionObject(new ForgeWriteFailed('GitHub answered 422 to a write.'));

        $forge->postReview('widgets', $change, new ReviewComment('Changes needed.', new ReviewToken(self::TOKEN), new JobCredentials()));
    }

    public function testItDoesNotChainTheTransportFailureOfThePostWhoseTraceHoldsTheToken(): void
    {
        $forge = $this->forge(static fn (string $url): ?ResponseInterface => str_ends_with($url, '/reviews') ? throw new TransportException('connection dropped') : null);
        $change = $forge->change(new ChangeId(self::PULL));

        $failure = $this->failureOfPost($forge, $change, $this->comment('Changes needed.'));

        self::assertInstanceOf(ForgeWriteFailed::class, $failure);
        self::assertNull($failure->getPrevious());
        self::assertStringContainsString(TransportException::class, $failure->getMessage());
    }

    public function testItNeverRetriesThePostAfterA502BecauseGitHubMayHaveStoredTheReview(): void
    {
        $forge = $this->forge(static fn (string $url): ?ResponseInterface => str_ends_with($url, '/reviews') ? new MockResponse('', ['http_code' => 502]) : null);
        $change = $forge->change(new ChangeId(self::PULL));

        $failure = $this->failureOfPost($forge, $change, $this->comment('Two reviewers approve.'));

        self::assertEquals(new ForgeWriteFailed('GitHub answered 502 to a write.'), $failure);
        self::assertCount(1, array_keys($this->requested, self::POST_REVIEW, true));
    }

    /**
     * @return iterable<string, array{int, class-string}>
     */
    public static function failedReadsOfTheHead(): iterable
    {
        yield 'a server error' => [500, ForgeReadFailed::class];
        yield 'a pull request gone' => [404, ChangeNotFound::class];
    }

    /**
     * @param class-string $readFailure
     */
    #[DataProvider('failedReadsOfTheHead')]
    public function testItTurnsAFailedReadOfTheHeadBeforeThePostIntoAForgeWriteFailure(int $status, string $readFailure): void
    {
        $change = $this->forge()->change(new ChangeId(self::PULL));
        $forge = $this->forge(static fn (string $url): ?ResponseInterface => str_ends_with($url, '/pulls/7') ? new MockResponse('{}', ['http_code' => $status]) : null);

        $failure = $this->failureOfPost($forge, $change, $this->comment('Two reviewers approve.'));

        self::assertInstanceOf(ForgeWriteFailed::class, $failure);
        self::assertInstanceOf($readFailure, $failure->getPrevious());
        self::assertNotContains(self::POST_REVIEW, $this->requested);
    }

    public function testItPostsTheReviewWithEveryCredentialOfTheJobRedacted(): void
    {
        $forge = $this->forge();
        $change = $forge->change(new ChangeId(self::PULL));

        $forge->postReview('widgets', $change, $this->comment('The test prints project-db-pass-42 in its log.', 'project-db-pass-42'));

        self::assertSame([
            'commit_id' => self::HEAD,
            'event' => 'COMMENT',
            'body' => "The test prints [redacted] in its log.\n\n<!-- intakt-review-token: ".self::TOKEN.' -->',
        ], json_decode($this->postedBody(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testItPostsNothingWhenTheRedactedTextStillTripsTheSecretScan(): void
    {
        $forge = $this->forge();
        $change = $forge->change(new ChangeId(self::PULL));
        $requestsBefore = count($this->requested);

        $failure = $this->failureOfPost($forge, $change, $this->comment("The fixture holds a key:\nsk_live_".str_repeat('4eC39HqLyjWDarjtT1zdp7dc', 2), 'project-db-pass-42'));

        self::assertEquals(ReviewTextHoldsSecret::afterRedaction(), $failure);
        self::assertCount($requestsBefore, $this->requested);
    }

    private function failureOfPost(GitHubForge $forge, Change $change, ReviewComment $comment): RuntimeException
    {
        try {
            $forge->postReview('widgets', $change, $comment);
        } catch (RuntimeException $failure) {
            return $failure;
        }

        throw new LogicException('The post was expected to fail.');
    }

    private function comment(string $body, string ...$credentials): ReviewComment
    {
        return new ReviewComment($body, new ReviewToken(self::TOKEN), new JobCredentials(...$credentials));
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
        // Retrying as the scoped client is in the container, so the tests see which requests are tried again.
        return $this->forgeOver(new RetryableHttpClient(new MockHttpClient(function (string $method, string $url, array $options) use ($override): ResponseInterface {
            $this->requested[] = $method.' '.$url;
            $this->sent[$method.' '.$url] = $options;

            $answer = $override instanceof Closure ? $override($url, $options) : null;

            return $answer ?? $this->answer($url, $options);
        }, 'https://api.github.com'), new GenericRetryStrategy(delayMs: 0), 2));
    }

    private function forgeOver(HttpClientInterface $http): GitHubForge
    {
        $clock = new MockClock('2026-11-25 10:00:00 UTC');
        $identity = new RecordedInstallation(self::MINTED)->client();
        $jwt = new AppJwt('12345', AppKey::generate()->private, $clock);
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, $identity, new NullLogger()), '678', $clock);
        $expected = ExpectedInstallation::fromSettings($this->expectedPermissions, 'acme/widget,acme/gadget');
        $readings = FreshReadings::at($clock);
        $readBack = new InstallationReadBack(new InstallationReader($jwt, $tokens, new GitHubCalls($identity, $readings, $tokens)), $expected, new NullLogger());

        $calls = new GitHubCalls($http, $readings, $tokens);
        $checked = new CheckedInstallationTokens($readBack, $tokens);

        return new GitHubForge($calls, $checked, $this->secretScan(), new PostedComments($calls, $checked, '12345'), new VerdictLocks(new LockFactory(new InMemoryStore())), new GitHubAppSetting('12345', new NullLogger()));
    }

    private function secretScan(): SecretScan
    {
        return new SecretScan(dirname(__DIR__, 3), 'gitleaks');
    }

    /**
     * @param array<mixed> $options
     */
    private function answer(string $url, array $options): ResponseInterface
    {
        $accept = self::header($options, 'accept');
        $path = explode('?', $url)[0];

        return match (true) {
            str_ends_with($path, '/reviews') => new MockResponse(self::fixture('review_posted.json'), ['http_code' => 200]),
            str_contains($url, '/contents/') => new MockResponse(self::fixture('contents_widget.txt')),
            str_contains($path, '/pulls/7') => new MockResponse(self::fixture($this->pullFixture)),
            str_ends_with($accept, 'github.diff') => new MockResponse(self::fixture('pull.diff')),
            default => new MockResponse(self::fixture($this->compareFixture)),
        };
    }

    /**
     * @param array<mixed> $options
     */
    private function isBranchComparison(string $url, array $options): bool
    {
        return str_contains($url, '/compare/main...') && !str_ends_with(self::header($options, 'accept'), 'github.diff');
    }

    private function postedBody(): string
    {
        $body = $this->sent[self::POST_REVIEW]['body'] ?? null;

        return is_string($body) ? $body : throw new LogicException('No review was posted.');
    }

    private static function contentsAnswer(ResponseInterface $response): Closure
    {
        return static fn (string $url): ?ResponseInterface => str_contains($url, '/contents/') ? $response : null;
    }

    private static function comparison(string $mergeBase, int $files): string
    {
        return json_encode([
            'status' => 'ahead',
            'merge_base_commit' => ['sha' => $mergeBase],
            'files' => array_map(static fn (int $number): array => ['filename' => 'docs/F'.$number.'.md', 'status' => 'added'], range(1, $files)),
        ], JSON_THROW_ON_ERROR);
    }

    private static function fixture(string $name): string
    {
        $content = file_get_contents(__DIR__.'/Fixtures/'.$name);

        return false === $content ? throw new LogicException('Missing fixture '.$name) : $content;
    }

    /**
     * @param array<mixed> $options
     */
    private static function header(array $options, string $name): string
    {
        $headers = $options['normalized_headers'] ?? [];
        $values = is_array($headers) ? ($headers[$name] ?? []) : [];

        return is_array($values) && is_string($values[0] ?? null) ? $values[0] : '';
    }
}
