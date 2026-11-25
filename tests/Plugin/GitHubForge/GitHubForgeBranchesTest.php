<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\BranchChange;
use Intakt\Plugin\Forge\Contract\BranchChangeState;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\ForgeBranch;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\CheckedInstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubBranchCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubForgeBranches;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\NextLinks;
use Intakt\Plugin\GitHubForge\Infrastructure\RepositoryAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

// Recorded GitHub answers keyed by method and URL (a body, or three digits for an HTTP status), with every request kept, because a test must see which pages were read and that no delete was sent.
#[CoversClass(GitHubForgeBranches::class)]
#[CoversClass(GitHubBranchCalls::class)]
#[UsesClass(RepositoryAddress::class)]
#[UsesClass(GitHubCalls::class)]
#[UsesClass(CheckedInstallationTokens::class)]
#[UsesClass(ForgeBranch::class)]
#[UsesClass(BranchChange::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(NextLinks::class)]
final class GitHubForgeBranchesTest extends TestCase
{
    private const string HEAD = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string OLDER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const string BASE = 'cccccccccccccccccccccccccccccccccccccccc';
    private const string REPOSITORY = 'https://api.github.com/repos/acme/widgets';
    private const string INTAKT = 'intakt@example.com';

    /** @var array<string, string> */
    private array $answers = [];
    /** @var array<string, string> */
    private array $links = [];
    /** @var list<string> */
    private array $requests = [];

    public function testBranchesAreListedWithTheirHeadAndWhetherTheyAreTheDefaultOrProtected(): void
    {
        $this->defaultBranchIs('main');
        $this->answers['GET '.$this->branchPage(1)] = sprintf('[{"name":"main","commit":{"sha":"%1$s"},"protected":true},{"name":"intakt/7-old","commit":{"sha":"%2$s"},"protected":false},{"name":"guarded","commit":{"sha":"%2$s"},"protected":true}]', self::BASE, self::HEAD);

        self::assertEquals(
            [new ForgeBranch('main', new CommitId(self::BASE), true, true, false), new ForgeBranch('intakt/7-old', new CommitId(self::HEAD), false, false, false), new ForgeBranch('guarded', new CommitId(self::HEAD), false, true, false)],
            $this->forge()->branches(),
        );
    }

    public function testABranchNamedAsAReleaseIsListedAsNotDeletable(): void
    {
        $this->defaultBranchIs('main');
        $this->answers['GET '.$this->branchPage(1)] = sprintf('[{"name":"release/1.x","commit":{"sha":"%s"},"protected":false}]', self::HEAD);

        $branch = $this->forge()->branches()[0];

        self::assertTrue($branch->isRelease());
        self::assertFalse($branch->isDeletable());
    }

    public function testABranchOnTheNextPageOfTheListingIsListed(): void
    {
        $this->defaultBranchIs('main');
        $this->answers['GET '.$this->branchPage(1)] = sprintf('[{"name":"main","commit":{"sha":"%s"},"protected":true}]', self::BASE);
        $this->links['GET '.$this->branchPage(1)] = '<'.self::REPOSITORY.'/branches?per_page=100&page=2>; rel="next"';
        $this->answers['GET '.$this->branchPage(2)] = sprintf('[{"name":"intakt/7-old","commit":{"sha":"%s"},"protected":false}]', self::HEAD);

        self::assertSame(['main', 'intakt/7-old'], array_map(static fn (ForgeBranch $branch): string => $branch->name, $this->forge()->branches()));
    }

    public function testABranchListedWithoutItsProtectedFlagFailsTheRead(): void
    {
        $this->defaultBranchIs('main');
        $this->answers['GET '.$this->branchPage(1)] = sprintf('[{"name":"intakt/7-old","commit":{"sha":"%s"}}]', self::HEAD);

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered a branch without "protected".'));

        $this->forge()->branches();
    }

    public function testAnEmptyObjectInPlaceOfTheBranchListingFailsTheRead(): void
    {
        $this->defaultBranchIs('main');
        $this->answers['GET '.$this->branchPage(1)] = '{}';

        $this->expectException(ForgeReadFailed::class);

        $this->forge()->branches();
    }

    public function testAListingThatNamesANextPageOnEveryPageFailsAtTheCap(): void
    {
        $this->defaultBranchIs('main');
        foreach (range(1, 100) as $page) {
            $this->answers['GET '.$this->branchPage($page)] = '[]';
            $this->links['GET '.$this->branchPage($page)] = '<'.self::REPOSITORY.'/branches?page='.($page + 1).'>; rel="next"';
        }

        $this->expectExceptionObject(new ForgeReadFailed('GitHub lists more than 100 pages, more than this forge reads.'));

        $this->forge()->branches();
    }

    public function testAnUnreadableLinkHeaderFailsTheRead(): void
    {
        $this->defaultBranchIs('main');
        $this->answers['GET '.$this->branchPage(1)] = '[]';
        $this->links['GET '.$this->branchPage(1)] = 'page=2; rel=next';

        $this->expectException(ForgeReadFailed::class);

        $this->forge()->branches();
    }

    public function testTheMergedPullRequestOfTheBranchsHeadIsItsChange(): void
    {
        $this->answers['GET '.$this->pulls(1)] = $this->pull('closed', '"2026-11-20T10:00:00Z"', self::HEAD);

        self::assertEquals(new BranchChange(BranchChangeState::Merged), $this->forge()->changeOf($this->branch()));
        self::assertSame(['GET '.$this->pulls(1)], $this->requests);
    }

    public function testAClosedUnmergedPullRequestCarriesItsLabels(): void
    {
        $this->answers['GET '.$this->pulls(1)] = $this->pull('closed', 'null', self::HEAD, 'keep');

        self::assertEquals(new BranchChange(BranchChangeState::ClosedUnmerged, 'keep'), $this->forge()->changeOf($this->branch()));
    }

    public function testAnOpenPullRequestOnTheSecondPageWinsOverANewerClosedOne(): void
    {
        $this->answers['GET '.$this->pulls(1)] = $this->pull('closed', 'null', self::HEAD);
        $this->links['GET '.$this->pulls(1)] = '<'.self::REPOSITORY.'/pulls?page=2>; rel="next"';
        $this->answers['GET '.$this->pulls(2)] = $this->pull('open', 'null', self::HEAD);

        self::assertSame(BranchChangeState::Open, $this->forge()->changeOf($this->branch())?->state);
    }

    public function testAKeepLabelOnAPullRequestOnTheSecondPageIsCarried(): void
    {
        $this->answers['GET '.$this->pulls(1)] = $this->pull('closed', '"2026-11-20T10:00:00Z"', self::HEAD);
        $this->links['GET '.$this->pulls(1)] = '<'.self::REPOSITORY.'/pulls?page=2>; rel="next"';
        $this->answers['GET '.$this->pulls(2)] = $this->pull('closed', 'null', self::OLDER, 'keep');

        self::assertEquals(new BranchChange(BranchChangeState::Merged, 'keep'), $this->forge()->changeOf($this->branch()));
    }

    public function testAPullRequestClosedBeforeTheBranchsHeadIsNoChangeOfIt(): void
    {
        $this->answers['GET '.$this->pulls(1)] = $this->pull('closed', '"2026-11-20T10:00:00Z"', self::OLDER);

        self::assertNull($this->forge()->changeOf($this->branch()));
    }

    public function testABranchWithoutAPullRequestOnAnyPageHasNone(): void
    {
        $this->answers['GET '.$this->pulls(1)] = '[]';
        $this->links['GET '.$this->pulls(1)] = '<'.self::REPOSITORY.'/pulls?page=2>; rel="next"';
        $this->answers['GET '.$this->pulls(2)] = '[]';

        self::assertNull($this->forge()->changeOf($this->branch()));
        self::assertCount(2, $this->requests);
    }

    public function testAPullRequestLookupGitHubCannotAnswerFailsInsteadOfMeaningNone(): void
    {
        $this->answers['GET '.$this->pulls(1)] = '500';

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered 500 to a read.'));

        $this->forge()->changeOf($this->branch());
    }

    public function testAPullRequestLookupWhoseSecondPageFailsFailsInsteadOfMeaningNone(): void
    {
        $this->answers['GET '.$this->pulls(1)] = '[]';
        $this->links['GET '.$this->pulls(1)] = '<'.self::REPOSITORY.'/pulls?page=2>; rel="next"';
        $this->answers['GET '.$this->pulls(2)] = 'not json';

        $this->expectException(ForgeReadFailed::class);
        $this->expectExceptionMessage('GitHub answered something other than JSON.');

        $this->forge()->changeOf($this->branch());
    }

    public function testAPullRequestWithoutItsLabelsFailsTheRead(): void
    {
        $this->answers['GET '.$this->pulls(1)] = sprintf('[{"state":"open","merged_at":null,"head":{"sha":"%s"}}]', self::HEAD);

        $this->expectExceptionObject(new ForgeReadFailed('GitHub listed a pull request without the list "labels".'));

        $this->forge()->changeOf($this->branch());
    }

    public function testAnObjectWithNumberedKeysInPlaceOfTheLabelsFailsTheRead(): void
    {
        $this->answers['GET '.$this->pulls(1)] = sprintf('[{"state":"open","merged_at":null,"labels":{"0":{"name":"keep"}},"head":{"sha":"%s"}}]', self::HEAD);

        $this->expectExceptionObject(new ForgeReadFailed('GitHub listed a pull request without the list "labels".'));

        $this->forge()->changeOf($this->branch());
    }

    public function testAClosedPullRequestWithoutItsMergeMomentFailsTheRead(): void
    {
        $this->answers['GET '.$this->pulls(1)] = sprintf('[{"state":"closed","labels":[],"head":{"sha":"%s"}}]', self::HEAD);

        $this->expectExceptionObject(new ForgeReadFailed('GitHub listed a pull request without "merged_at".'));

        $this->forge()->changeOf($this->branch());
    }

    public function testAPullRequestWithAMalformedHeadFailsTheRead(): void
    {
        $this->answers['GET '.$this->pulls(1)] = $this->pull('closed', 'null', 'not-a-commit');

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered a commit that is not a commit id.'));

        $this->forge()->changeOf($this->branch());
    }

    public function testAnEmptyObjectInPlaceOfThePullRequestListingFailsTheRead(): void
    {
        $this->answers['GET '.$this->pulls(1)] = '{}';

        $this->expectException(ForgeReadFailed::class);

        $this->forge()->changeOf($this->branch());
    }

    public function testACommitByAnotherAuthorIsAPersons(): void
    {
        $this->defaultBranchAt(self::BASE);
        $this->answers['GET '.$this->compare(1)] = $this->comparison(1, $this->commit('alice@example.com', self::INTAKT));

        self::assertTrue($this->forge()->hasCommitByAPerson($this->branch(), self::INTAKT));
    }

    public function testACommitByAnotherCommitterIsAPersons(): void
    {
        $this->defaultBranchAt(self::BASE);
        $this->answers['GET '.$this->compare(1)] = $this->comparison(1, $this->commit(self::INTAKT, 'alice@example.com'));

        self::assertTrue($this->forge()->hasCommitByAPerson($this->branch(), self::INTAKT));
    }

    public function testABranchOfOnlyIntaktsCommitsHasNoneByAPersonWhateverTheLetterCase(): void
    {
        $this->defaultBranchAt(self::BASE);
        $this->answers['GET '.$this->compare(1)] = $this->comparison(2, $this->commit(self::INTAKT, self::INTAKT), $this->commit('Intakt@Example.com', self::INTAKT));

        self::assertFalse($this->forge()->hasCommitByAPerson($this->branch(), self::INTAKT));
    }

    public function testACommitByAPersonOnTheSecondPageOfTheComparisonWithTheDefaultBranchsHeadIsFound(): void
    {
        $this->defaultBranchAt(self::BASE);
        $this->answers['GET '.$this->compare(1)] = $this->comparison(2, $this->commit(self::INTAKT, self::INTAKT));
        $this->links['GET '.$this->compare(1)] = '<'.self::REPOSITORY.'/compare?page=2>; rel="next"';
        $this->answers['GET '.$this->compare(2)] = $this->comparison(2, $this->commit('alice@example.com', self::INTAKT));

        self::assertTrue($this->forge()->hasCommitByAPerson($this->branch(), self::INTAKT));
    }

    public function testAComparisonWhoseCommitsFallShortOfItsTotalFailsInsteadOfMeaningNoPerson(): void
    {
        $this->defaultBranchAt(self::BASE);
        $this->answers['GET '.$this->compare(1)] = $this->comparison(300, $this->commit(self::INTAKT, self::INTAKT));

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered a comparison whose commits do not add up to its total.'));

        $this->forge()->hasCommitByAPerson($this->branch(), self::INTAKT);
    }

    public function testACommitWithoutAnAuthorEmailFailsTheRead(): void
    {
        $this->defaultBranchAt(self::BASE);
        $this->answers['GET '.$this->compare(1)] = $this->comparison(1, $this->commit('', self::INTAKT));

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered without "commit.author.email".'));

        $this->forge()->hasCommitByAPerson($this->branch(), self::INTAKT);
    }

    public function testAComparisonGitHubCannotAnswerFailsInsteadOfMeaningNoPerson(): void
    {
        $this->defaultBranchAt(self::BASE);
        $this->answers['GET '.$this->compare(1)] = '404';

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered 404 to a read.'));

        $this->forge()->hasCommitByAPerson($this->branch(), self::INTAKT);
    }

    public function testAnEmptyIdentityFailsTheRead(): void
    {
        $this->expectException(ForgeReadFailed::class);

        $this->forge()->hasCommitByAPerson($this->branch(), ' ');
    }

    public function testABranchStillAtTheListedHeadIsDeletedByItsRefRightAfterItsHeadIsRead(): void
    {
        $this->defaultBranchIs('main');
        $this->answers['GET '.self::REPOSITORY.'/branches/intakt/7-old'] = $this->current('intakt/7-old', self::HEAD, 'false');
        $this->answers['DELETE '.self::REPOSITORY.'/git/refs/heads/intakt/7-old'] = '204';

        $this->forge()->delete($this->branch());

        self::assertSame(['GET '.self::REPOSITORY, 'GET '.self::REPOSITORY.'/branches/intakt/7-old', 'DELETE '.self::REPOSITORY.'/git/refs/heads/intakt/7-old'], $this->requests);
    }

    public function testABranchWhoseHeadMovedBeforeTheFinalReadIsNotDeleted(): void
    {
        $this->defaultBranchIs('main');
        $this->answers['GET '.self::REPOSITORY.'/branches/intakt/7-old'] = $this->current('intakt/7-old', self::OLDER, 'false');

        $this->expectExceptionObject(new ForgeWriteFailed(sprintf('The branch "intakt/7-old" was not deleted: it moved to %s since it was listed at %s', self::OLDER, self::HEAD)));

        try {
            $this->forge()->delete($this->branch());
        } finally {
            self::assertNotContains('DELETE '.self::REPOSITORY.'/git/refs/heads/intakt/7-old', $this->requests);
        }
    }

    /**
     * @return iterable<string, array{ForgeBranch}>
     */
    public static function branchesNeverDeleted(): iterable
    {
        yield 'default' => [new ForgeBranch('main', new CommitId(self::HEAD), true, false, false)];
        yield 'protected' => [new ForgeBranch('intakt/7-old', new CommitId(self::HEAD), false, true, false)];
        yield 'release' => [new ForgeBranch('release/1.x', new CommitId(self::HEAD), false, false, false)];
    }

    #[DataProvider('branchesNeverDeleted')]
    public function testADefaultProtectedOrReleaseBranchIsRefusedWithoutACall(ForgeBranch $branch): void
    {
        try {
            $this->forge()->delete($branch);
            self::fail('A branch that is never deleted was deleted.');
        } catch (ForgeWriteFailed) {
            self::assertSame([], $this->requests);
        }
    }

    public function testABranchThatBecameTheDefaultIsNotDeleted(): void
    {
        $this->defaultBranchIs('intakt/7-old');

        $this->expectExceptionObject(new ForgeWriteFailed('The branch "intakt/7-old" was not deleted: it is the default branch now'));

        $this->forge()->delete($this->branch());
    }

    public function testABranchThatBecameProtectedIsNotDeleted(): void
    {
        $this->defaultBranchIs('main');
        $this->answers['GET '.self::REPOSITORY.'/branches/intakt/7-old'] = $this->current('intakt/7-old', self::HEAD, 'true');

        $this->expectExceptionObject(new ForgeWriteFailed('The branch "intakt/7-old" was not deleted: it is protected now'));

        $this->forge()->delete($this->branch());
    }

    public function testABranchGitHubAnswersUnderAnotherNameIsNotDeleted(): void
    {
        $this->defaultBranchIs('main');
        $this->answers['GET '.self::REPOSITORY.'/branches/intakt/7-old'] = $this->current('intakt/7-renamed', self::HEAD, 'false');

        $this->expectExceptionObject(new ForgeWriteFailed('The branch "intakt/7-old" was not deleted: GitHub answered another branch'));

        $this->forge()->delete($this->branch());
    }

    public function testABranchWhoseHeadCannotBeReadIsNotDeleted(): void
    {
        $this->defaultBranchIs('main');

        $this->expectExceptionObject(new ForgeWriteFailed('The branch "intakt/7-old" was not deleted: GitHub answered 404 to a read.'));

        $this->forge()->delete($this->branch());
    }

    public function testABranchNameWithADotLedPartIsNotDeleted(): void
    {
        $this->defaultBranchIs('main');

        $this->expectExceptionObject(new ForgeWriteFailed('The branch "intakt/../hooks" was not deleted: The branch name "intakt/../hooks" is not one git gives a branch.'));

        $this->forge()->delete(new ForgeBranch('intakt/../hooks', new CommitId(self::HEAD), false, false, false));
    }

    public function testADeletionGitHubRefusesFails(): void
    {
        $this->defaultBranchIs('main');
        $this->answers['GET '.self::REPOSITORY.'/branches/intakt/7-old'] = $this->current('intakt/7-old', self::HEAD, 'false');
        $this->answers['DELETE '.self::REPOSITORY.'/git/refs/heads/intakt/7-old'] = '422';

        $this->expectExceptionObject(new ForgeWriteFailed('GitHub answered 422 to a branch deletion.'));

        $this->forge()->delete($this->branch());
    }

    private function defaultBranchIs(string $name): void
    {
        $this->answers['GET '.self::REPOSITORY] = sprintf('{"default_branch":"%s"}', $name);
    }

    private function branchPage(int $page): string
    {
        return self::REPOSITORY.'/branches?per_page=100&page='.$page;
    }

    private function forge(): GitHubForgeBranches
    {
        $clock = new MockClock('2026-11-25 10:00:00 UTC');
        $identity = new RecordedInstallation('installation-secret')->client();
        $jwt = new AppJwt('12345', AppKey::generate()->private, $clock);
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, $identity, new NullLogger()), '678', $clock);
        $expected = ExpectedInstallation::fromSettings('contents:write,pull_requests:write,workflows:write', 'acme/widget,acme/gadget');
        $readings = FreshReadings::at($clock);
        $readBack = new InstallationReadBack(new InstallationReader($jwt, $tokens, new GitHubCalls($identity, $readings, $tokens)), $expected, new NullLogger());
        $http = new MockHttpClient($this->answer(...), 'https://api.github.com');

        return new GitHubForgeBranches(new RepositoryAddress('acme', 'widgets'), new GitHubBranchCalls(new GitHubCalls($http, $readings, $tokens), new CheckedInstallationTokens($readBack, $tokens), new NextLinks()));
    }

    private function pulls(int $page): string
    {
        return self::REPOSITORY.'/pulls?state=all&head=acme:intakt/7-old&sort=created&direction=desc&per_page=100&page='.$page;
    }

    private function pull(string $state, string $mergedAt, string $head, string ...$labels): string
    {
        return sprintf('[{"state":"%s","merged_at":%s,"labels":%s,"head":{"sha":"%s"}}]', $state, $mergedAt, json_encode(array_map(static fn (string $label): array => ['name' => $label], $labels)), $head);
    }

    private function branch(): ForgeBranch
    {
        return new ForgeBranch('intakt/7-old', new CommitId(self::HEAD), false, false, false);
    }

    private function defaultBranchAt(string $head): void
    {
        $this->defaultBranchIs('main');
        $this->answers['GET '.self::REPOSITORY.'/branches/main'] = $this->current('main', $head, 'true');
    }

    private function compare(int $page): string
    {
        return self::REPOSITORY.'/compare/'.self::BASE.'...'.self::HEAD.'?per_page=100&page='.$page;
    }

    private function comparison(int $total, string ...$commits): string
    {
        return sprintf('{"total_commits":%d,"commits":[%s]}', $total, implode(',', $commits));
    }

    private function commit(string $author, string $committer): string
    {
        return sprintf('{"commit":{"author":{"email":"%s"},"committer":{"email":"%s"}}}', $author, $committer);
    }

    private function current(string $name, string $head, string $protected): string
    {
        return sprintf('{"name":"%s","commit":{"sha":"%s"},"protected":%s}', $name, $head, $protected);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function answer(string $method, string $url, array $options): ResponseInterface
    {
        $this->requests[] = $method.' '.$url;
        $answer = $this->answers[$method.' '.$url] ?? '404';
        $headers = isset($this->links[$method.' '.$url]) ? ['Link: '.$this->links[$method.' '.$url]] : [];

        return 1 === preg_match('/^\d{3}$/', $answer) ? new MockResponse('', ['http_code' => (int) $answer]) : new MockResponse($answer, ['response_headers' => $headers]);
    }
}
