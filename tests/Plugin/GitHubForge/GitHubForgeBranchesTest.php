<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use DateTimeImmutable;
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
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubMoments;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\NextLinks;
use Intakt\Plugin\GitHubForge\Infrastructure\RepositoryAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\SteeringIdentities;
use Intakt\Plugin\GitHubForge\Infrastructure\TrustAnswers;
use Intakt\Plugin\GitHubForge\Infrastructure\WatchedRepositories;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(GitHubForgeBranches::class)]
#[UsesClass(GitHubBranchCalls::class)]
#[UsesClass(WatchedRepositories::class)]
#[UsesClass(RepositoryAddress::class)]
#[UsesClass(GitHubMoments::class)]
#[UsesClass(GitHubCalls::class)]
#[UsesClass(CheckedInstallationTokens::class)]
#[UsesClass(ForgeBranch::class)]
#[UsesClass(BranchChange::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(NextLinks::class)]
#[UsesClass(SteeringIdentities::class)]
#[UsesClass(TrustAnswers::class)]
final class GitHubForgeBranchesTest extends TestCase
{
    private const string HEAD = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string OLDER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const string REPOSITORY = 'https://api.github.com/repos/acme/widgets';

    /** @var array<string, string> */
    private array $answers = [];
    /** @var list<string> */
    private array $requests = [];
    /** @var array<string, string> */
    private array $links = [];

    public function testBranchesAreListedWithTheirHeadAndWhetherTheyAreTheDefaultOrProtected(): void
    {
        $this->answers['GET '.self::REPOSITORY] = '{"default_branch":"main"}';
        $this->answers['GET '.self::REPOSITORY.'/branches?per_page=100&page=1'] = sprintf('[{"name":"main","commit":{"sha":"%1$s"},"protected":true},{"name":"intakt/7-old","commit":{"sha":"%2$s"},"protected":false}]', self::OLDER, self::HEAD);

        self::assertEquals(
            [new ForgeBranch('main', new CommitId(self::OLDER), true, true, false), new ForgeBranch('intakt/7-old', new CommitId(self::HEAD), false, false, false)],
            $this->forge()->branchesOf('alpha'),
        );
    }

    public function testABranchListedWithoutItsProtectedFlagFailsTheRead(): void
    {
        $this->answers['GET '.self::REPOSITORY] = '{"default_branch":"main"}';
        $this->answers['GET '.self::REPOSITORY.'/branches?per_page=100&page=1'] = sprintf('[{"name":"intakt/7-old","commit":{"sha":"%s"}}]', self::HEAD);

        $this->expectException(ForgeReadFailed::class);

        $this->forge()->branchesOf('alpha');
    }

    public function testTheMergedPullRequestOfTheBranchsHeadIsItsChange(): void
    {
        $this->answers['GET '.$this->pulls()] = sprintf('[{"state":"closed","merged_at":"2026-11-20T10:00:00Z","closed_at":"2026-11-20T10:00:00Z","labels":[],"head":{"sha":"%s"}}]', self::HEAD);

        self::assertEquals(new BranchChange(BranchChangeState::Merged, new DateTimeImmutable('2026-11-20T10:00:00Z'), []), $this->forge()->changeOf('alpha', $this->branch(self::HEAD)));
    }

    public function testAClosedUnmergedPullRequestCarriesTheMomentItClosedAndItsLabels(): void
    {
        $this->answers['GET '.$this->pulls()] = sprintf('[{"state":"closed","merged_at":null,"closed_at":"2026-10-01T08:00:00Z","labels":[{"name":"keep"}],"head":{"sha":"%s"}}]', self::HEAD);

        self::assertEquals(new BranchChange(BranchChangeState::ClosedUnmerged, new DateTimeImmutable('2026-10-01T08:00:00Z'), ['keep']), $this->forge()->changeOf('alpha', $this->branch(self::HEAD)));
    }

    public function testAnOpenPullRequestWinsOverAnOlderClosedOneOfTheSameHead(): void
    {
        $this->answers['GET '.$this->pulls()] = sprintf('[{"state":"closed","merged_at":null,"closed_at":"2026-10-01T08:00:00Z","labels":[],"head":{"sha":"%1$s"}},{"state":"open","labels":[],"head":{"sha":"%1$s"}}]', self::HEAD);

        self::assertSame(BranchChangeState::Open, $this->forge()->changeOf('alpha', $this->branch(self::HEAD))?->state);
    }

    public function testAPullRequestOpenedForAnOlderHeadIsNoChangeOfTheBranch(): void
    {
        $this->answers['GET '.$this->pulls()] = sprintf('[{"state":"closed","merged_at":"2026-11-20T10:00:00Z","closed_at":"2026-11-20T10:00:00Z","labels":[],"head":{"sha":"%s"}}]', self::OLDER);

        self::assertNull($this->forge()->changeOf('alpha', $this->branch(self::HEAD)));
    }

    public function testAPullRequestWithoutItsLabelsFailsTheRead(): void
    {
        $this->answers['GET '.$this->pulls()] = sprintf('[{"state":"open","head":{"sha":"%s"}}]', self::HEAD);

        $this->expectException(ForgeReadFailed::class);

        $this->forge()->changeOf('alpha', $this->branch(self::HEAD));
    }

    public function testABranchStillAtTheListedHeadIsDeletedByItsRef(): void
    {
        $this->answers['GET '.self::REPOSITORY.'/branches/intakt/7-old'] = sprintf('{"name":"intakt/7-old","commit":{"sha":"%s"}}', self::HEAD);
        $this->answers['DELETE '.self::REPOSITORY.'/git/refs/heads/intakt/7-old'] = '204';

        $this->forge()->deleteBranch('alpha', $this->branch(self::HEAD));

        self::assertContains('DELETE '.self::REPOSITORY.'/git/refs/heads/intakt/7-old', $this->requests);
    }

    public function testABranchThatMovedSinceItWasListedIsNotDeleted(): void
    {
        $this->answers['GET '.self::REPOSITORY.'/branches/intakt/7-old'] = sprintf('{"name":"intakt/7-old","commit":{"sha":"%s"}}', self::OLDER);

        try {
            $this->forge()->deleteBranch('alpha', $this->branch(self::HEAD));
            self::fail('A moved branch was deleted.');
        } catch (ForgeWriteFailed) {
            self::assertSame(['GET '.self::REPOSITORY.'/branches/intakt/7-old'], $this->requests);
        }
    }

    public function testADeletionGitHubRefusesFails(): void
    {
        $this->answers['GET '.self::REPOSITORY.'/branches/intakt/7-old'] = sprintf('{"name":"intakt/7-old","commit":{"sha":"%s"}}', self::HEAD);
        $this->answers['DELETE '.self::REPOSITORY.'/git/refs/heads/intakt/7-old'] = '422';

        $this->expectException(ForgeWriteFailed::class);

        $this->forge()->deleteBranch('alpha', $this->branch(self::HEAD));
    }

    public function testABranchNamedAsAReleaseIsReportedAsOne(): void
    {
        $this->answers['GET '.self::REPOSITORY] = '{"default_branch":"main"}';
        $this->answers['GET '.self::REPOSITORY.'/branches?per_page=100&page=1'] = sprintf('[{"name":"release/1.x","commit":{"sha":"%s"},"protected":false}]', self::HEAD);

        self::assertTrue($this->forge()->branchesOf('alpha')[0]->isRelease);
    }

    public function testAKeepLabelledOpenPullRequestOnALaterPageIsFound(): void
    {
        $this->answers['GET '.$this->pulls()] = sprintf('[{"state":"closed","merged_at":null,"closed_at":"2026-10-01T08:00:00Z","labels":[],"head":{"sha":"%s"}}]', self::OLDER);
        $this->links['GET '.$this->pulls()] = '<'.self::REPOSITORY.'/pulls?page=2>; rel="next"';
        $this->answers['GET '.$this->pulls(2)] = sprintf('[{"state":"open","labels":[{"name":"keep"}],"head":{"sha":"%s"}}]', self::HEAD);

        self::assertEquals(new BranchChange(BranchChangeState::Open, null, ['keep']), $this->forge()->changeOf('alpha', $this->branch(self::HEAD)));
    }

    public function testAKeepLabelOnAnOlderClosedPullRequestOfTheSameHeadIsKept(): void
    {
        $this->answers['GET '.$this->pulls()] = sprintf('[{"state":"closed","merged_at":null,"closed_at":"2026-10-02T08:00:00Z","labels":[],"head":{"sha":"%s"}}]', self::HEAD);
        $this->links['GET '.$this->pulls()] = '<'.self::REPOSITORY.'/pulls?page=2>; rel="next"';
        $this->answers['GET '.$this->pulls(2)] = sprintf('[{"state":"closed","merged_at":null,"closed_at":"2026-10-01T08:00:00Z","labels":[{"name":"keep"}],"head":{"sha":"%s"}}]', self::HEAD);

        self::assertEquals(new BranchChange(BranchChangeState::ClosedUnmerged, new DateTimeImmutable('2026-10-02T08:00:00Z'), ['keep']), $this->forge()->changeOf('alpha', $this->branch(self::HEAD)));
    }

    public function testAPullRequestWithAMalformedHeadFailsTheRead(): void
    {
        $this->answers['GET '.$this->pulls()] = '[{"state":"open","labels":[],"head":{"sha":"not-a-commit"}}]';

        $this->expectException(ForgeReadFailed::class);

        $this->forge()->changeOf('alpha', $this->branch(self::HEAD));
    }

    public function testAnEmptyObjectInPlaceOfTheLabelsFailsTheRead(): void
    {
        $this->answers['GET '.$this->pulls()] = sprintf('[{"state":"open","labels":{},"head":{"sha":"%s"}}]', self::HEAD);

        $this->expectException(ForgeReadFailed::class);

        $this->forge()->changeOf('alpha', $this->branch(self::HEAD));
    }

    public function testAnEmptyObjectInPlaceOfAListingFailsTheRead(): void
    {
        $this->answers['GET '.$this->pulls()] = '{}';

        $this->expectException(ForgeReadFailed::class);

        $this->forge()->changeOf('alpha', $this->branch(self::HEAD));
    }

    public function testAnObjectWithNumberedKeysInPlaceOfTheListingFailsTheRead(): void
    {
        $this->answers['GET '.$this->pulls()] = sprintf('{"0":{"state":"open","labels":[],"head":{"sha":"%s"}}}', self::HEAD);

        $this->expectException(ForgeReadFailed::class);

        $this->forge()->changeOf('alpha', $this->branch(self::HEAD));
    }

    public function testAnObjectWithNumberedKeysInPlaceOfTheLabelsFailsTheRead(): void
    {
        $this->answers['GET '.$this->pulls()] = sprintf('[{"state":"open","labels":{"0":{"name":"keep"}},"head":{"sha":"%s"}}]', self::HEAD);

        $this->expectException(ForgeReadFailed::class);

        $this->forge()->changeOf('alpha', $this->branch(self::HEAD));
    }

    public function testANextLinkBackToTheSamePageFailsTheReadAfterOneRequest(): void
    {
        $this->answers['GET '.$this->pulls()] = '[]';
        $this->links['GET '.$this->pulls()] = '<'.self::REPOSITORY.'/pulls?page=1>; rel="next"';
        $this->answers['GET '.$this->pulls(2)] = '[]';

        try {
            $this->forge()->changeOf('alpha', $this->branch(self::HEAD));
            self::fail('A next link back to the same page was followed.');
        } catch (ForgeReadFailed) {
            self::assertSame(['GET '.$this->pulls()], $this->requests);
        }
    }

    public function testANextLinkThatSkipsAPageFailsTheRead(): void
    {
        $this->answers['GET '.$this->pulls()] = '[]';
        $this->links['GET '.$this->pulls()] = '<'.self::REPOSITORY.'/pulls?page=3>; rel="next"';
        $this->answers['GET '.$this->pulls(2)] = '[]';

        $this->expectException(ForgeReadFailed::class);

        $this->forge()->changeOf('alpha', $this->branch(self::HEAD));
    }

    public function testAListingOfMoreThanTenPagesIsReadInFull(): void
    {
        foreach (range(1, 11) as $page) {
            $this->answers['GET '.$this->pulls($page)] = '[]';
            $this->links['GET '.$this->pulls($page)] = '<'.self::REPOSITORY.'/pulls?page='.($page + 1).'>; rel="next"';
        }
        $this->answers['GET '.$this->pulls(12)] = sprintf('[{"state":"open","labels":[],"head":{"sha":"%s"}}]', self::HEAD);

        self::assertSame(BranchChangeState::Open, $this->forge()->changeOf('alpha', $this->branch(self::HEAD))?->state);
    }

    public function testTheComparisonBaseIsPinnedToACommitBeforePaging(): void
    {
        $this->defaultBranchAt(self::OLDER);
        $this->answers['GET https://api.github.com/app'] = '{"slug":"intakt-app"}';
        $this->answers['GET '.$this->compare()] = '{"commits":[{"author":{"login":"intakt-app[bot]"}}]}';
        $this->links['GET '.$this->compare()] = '<'.self::REPOSITORY.'/compare?page=2>; rel="next"';
        $this->answers['GET '.$this->compare(2)] = '{"commits":[{"author":{"login":"alice"}}]}';

        self::assertTrue($this->forge()->hasCommitByPerson('alpha', $this->branch(self::HEAD)));
        self::assertNotContains('GET '.self::REPOSITORY.'/compare/main...'.self::HEAD.'?per_page=100&page=1', $this->requests);
    }

    public function testAnEmptyObjectInPlaceOfTheCommitsFailsTheRead(): void
    {
        $this->defaultBranchAt(self::OLDER);
        $this->answers['GET '.$this->compare()] = '{"commits":{}}';

        $this->expectException(ForgeReadFailed::class);

        $this->forge()->hasCommitByPerson('alpha', $this->branch(self::HEAD));
    }

    public function testAPullRequestReadGitHubCannotAnswerFailsInsteadOfMeaningNone(): void
    {
        $this->expectException(ForgeReadFailed::class);

        $this->forge()->changeOf('alpha', $this->branch(self::HEAD));
    }

    public function testACommitByAPersonIsFoundOnALaterPage(): void
    {
        $this->defaultBranchAt(self::OLDER);
        $this->answers['GET https://api.github.com/app'] = '{"slug":"intakt-app"}';
        $this->answers['GET '.$this->compare()] = '{"commits":[{"author":{"login":"intakt-app[bot]"}}]}';
        $this->links['GET '.$this->compare()] = '<'.self::REPOSITORY.'/compare?page=2>; rel="next"';
        $this->answers['GET '.$this->compare(2)] = '{"commits":[{"author":{"login":"alice"}}]}';

        self::assertTrue($this->forge()->hasCommitByPerson('alpha', $this->branch(self::HEAD)));
    }

    public function testACommitWithoutAGitHubAccountIsAPersons(): void
    {
        $this->defaultBranchAt(self::OLDER);
        $this->answers['GET '.$this->compare()] = '{"commits":[{"author":null}]}';

        self::assertTrue($this->forge()->hasCommitByPerson('alpha', $this->branch(self::HEAD)));
    }

    public function testABranchOfOnlyIntaktsCommitsHasNoneByAPerson(): void
    {
        $this->defaultBranchAt(self::OLDER);
        $this->answers['GET https://api.github.com/app'] = '{"slug":"intakt-app"}';
        $this->answers['GET '.$this->compare()] = '{"commits":[{"author":{"login":"intakt-app[bot]"}}]}';

        self::assertFalse($this->forge()->hasCommitByPerson('alpha', $this->branch(self::HEAD)));
    }

    public function testACommitByAnotherBotIsAPersonsBecauseOnlyThisAppIsIntakt(): void
    {
        $this->defaultBranchAt(self::OLDER);
        $this->answers['GET https://api.github.com/app'] = '{"slug":"intakt-app"}';
        $this->answers['GET '.$this->compare()] = '{"commits":[{"author":{"login":"other-app[bot]"}}]}';

        self::assertTrue($this->forge()->hasCommitByPerson('alpha', $this->branch(self::HEAD)));
    }

    public function testACommitReadGitHubCannotAnswerFailsInsteadOfMeaningNoPerson(): void
    {
        $this->defaultBranchAt(self::OLDER);

        $this->expectException(ForgeReadFailed::class);

        $this->forge()->hasCommitByPerson('alpha', $this->branch(self::HEAD));
    }

    public function testABranchWhoseHeadCannotBeReadBeforeTheDeleteIsNotDeleted(): void
    {
        try {
            $this->forge()->deleteBranch('alpha', $this->branch(self::HEAD));
            self::fail('A branch whose head was unread was deleted.');
        } catch (ForgeWriteFailed) {
            self::assertNotContains('DELETE '.self::REPOSITORY.'/git/refs/heads/intakt/7-old', $this->requests);
        }
    }

    public function testOnlyAProjectWithAGitHubRepositoryIsServed(): void
    {
        self::assertTrue($this->forge()->servesProject('alpha'));
        self::assertFalse($this->forge()->servesProject('beta'));
    }

    private function defaultBranchAt(string $head): void
    {
        $this->answers['GET '.self::REPOSITORY] = '{"default_branch":"main"}';
        $this->answers['GET '.self::REPOSITORY.'/branches/main'] = sprintf('{"name":"main","commit":{"sha":"%s"}}', $head);
    }

    private function compare(int $page = 1): string
    {
        return self::REPOSITORY.'/compare/'.self::OLDER.'...'.self::HEAD.'?per_page=100&page='.$page;
    }

    private function pulls(int $page = 1): string
    {
        return self::REPOSITORY.'/pulls?state=all&head=acme:intakt/7-old&per_page=100&page='.$page;
    }

    private function branch(string $head): ForgeBranch
    {
        return new ForgeBranch('intakt/7-old', new CommitId($head), false, false, false);
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

        return new GitHubForgeBranches(new GitHubBranchCalls(new GitHubCalls($http, $readings, $tokens), new CheckedInstallationTokens($readBack, $tokens), new NextLinks()), new WatchedRepositories(['alpha' => ['github' => 'acme/widgets'], 'beta' => []]), new GitHubMoments(), new SteeringIdentities(new TrustAnswers(new GitHubCalls($http, $readings, $tokens)), $jwt, 'intakt-app'));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function answer(string $method, string $url, array $options): ResponseInterface
    {
        $this->requests[] = $method.' '.$url;
        $answer = $this->answers[$method.' '.$url] ?? '404';

        return 1 === preg_match('/^\d{3}$/', $answer) ? new MockResponse('', ['http_code' => (int) $answer]) : new MockResponse($answer, ['response_headers' => isset($this->links[$method.' '.$url]) ? ['Link: '.$this->links[$method.' '.$url]] : []]);
    }
}
