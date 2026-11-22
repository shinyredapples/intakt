<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueListing;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\GitHubForge\Infrastructure\ChangedWhileServed;
use Intakt\Plugin\GitHubForge\Infrastructure\EntityTags;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueReads;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubMoments;
use Intakt\Plugin\GitHubForge\Infrastructure\NextLinks;
use Intakt\Plugin\GitHubForge\Infrastructure\RepositoryAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\WatchedRepositories;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitHubIssueReads::class)]
#[CoversClass(ChangedWhileServed::class)]
#[CoversClass(EntityTags::class)]
#[CoversClass(NextLinks::class)]
#[CoversClass(IssueListing::class)]
#[CoversClass(ListedIssue::class)]
#[UsesClass(RepositoryAddress::class)]
#[UsesClass(WatchedRepositories::class)]
#[UsesClass(GitHubMoments::class)]
#[UsesClass(GitHubCalls::class)]
final class GitHubIssueReadsTest extends TestCase
{
    public function testThreeFullPagesLinkedByNextAreReadInOrder(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(1, 100), 2 => $this->page(101, 100), 3 => $this->page(201, 100)];
        $api->namingNext = [1, 2];
        $api->stable = true;

        $listing = $api->reads()->listOpen('alpha', null);

        self::assertSame(range(1, 300), array_map(static fn (ListedIssue $issue): int => $issue->number, $listing->issues()));
        self::assertStringContainsString('page=1', $api->requests[0]);
        self::assertStringContainsString('page=2', $api->requests[1]);
        self::assertStringContainsString('page=3', $api->requests[2]);
    }

    public function testMoreIssuesThanOnePageHoldsAreListedInFullWithTheIssueThatSortsFirstOnTheLastPage(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(2, 100), 2 => $this->page(1, 1)];
        $api->namingNext = [1];
        $api->stable = true;

        $numbers = array_map(static fn (ListedIssue $issue): int => $issue->number, $api->reads()->listOpen('alpha', null)->issues());

        self::assertCount(101, $numbers);
        self::assertSame(1, end($numbers));
    }

    public function testEveryPageBeforeTheLastIsAskedAgainWithItsValidatorOnceTheLastIsRead(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(1, 100), 2 => $this->page(101, 100), 3 => $this->page(201, 100)];
        $api->namingNext = [1, 2];
        $api->stable = true;

        $api->reads()->listOpen('alpha', null);

        self::assertCount(5, $api->requests);
        self::assertStringContainsString('page=1', $api->requests[3]);
        self::assertStringContainsString('page=2', $api->requests[4]);
        self::assertSame(['W/"issues-1"', 'W/"issues-1.p2"'], $api->conditions);
    }

    public function testAnEarlierPageThatIsNotConfirmedUnchangedFailsTheListing(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(1, 100), 2 => $this->page(102, 1)];
        $api->namingNext = [1];

        self::assertSame('GitHub did not confirm that page 1 of the open issues stayed the same while the later pages were read.', $this->failureOf($api)->getMessage());
    }

    public function testAnIssueListedOnTwoPagesFailsTheListingEvenWhenEveryEarlierPageIsConfirmed(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(1, 100), 2 => $this->page(100, 100), 3 => $this->page(201, 1)];
        $api->namingNext = [1, 2];
        $api->stable = true;

        self::assertSame('GitHub listed issue #100 on two pages, so the open issues moved while they were read.', $this->failureOf($api)->getMessage());
    }

    public function testAWildcardValidatorOnAnEarlierPageFailsTheListing(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(1, 100), 2 => $this->page(102, 1)];
        $api->namingNext = [1];
        $api->etag = '*';
        $api->stable = true;

        self::assertSame('GitHub answered a validator that is not an entity tag.', $this->failureOf($api)->getMessage());
    }

    public function testAWildcardValidatorOnASinglePageFailsTheListing(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(1, 3)];
        $api->etag = '*';

        self::assertSame('GitHub answered a validator that is not an entity tag.', $this->failureOf($api)->getMessage());
    }

    public function testAStrongValidatorIsAskedAgainAsItWasServed(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(1, 100), 2 => $this->page(101, 1)];
        $api->namingNext = [1];
        $api->etag = '"issues-1"';
        $api->stable = true;

        self::assertCount(101, $api->reads()->listOpen('alpha', null)->issues());
        self::assertSame(['"issues-1"'], $api->conditions);
    }

    public function testAnEarlierPageWithAnIssueUpdatedInTheSecondsItWasServedFailsTheListing(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(1, 100, '2026-11-25T09:59:56Z'), 2 => $this->page(101, 1)];
        $api->namingNext = [1];
        $api->stable = true;

        self::assertSame('Issue #1 on page 1 of the open issues changed in the seconds GitHub served the page, so a close and reopen while the later pages were read cannot be ruled out.', $this->failureOf($api)->getMessage());
    }

    public function testAnEarlierPageWithAnIssueUpdatedExactlyTheClockSlackBeforeItWasServedFailsTheListing(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(1, 100, '2026-11-25T09:59:55Z'), 2 => $this->page(102, 1)];
        $api->namingNext = [1];
        $api->stable = true;

        self::assertSame('Issue #1 on page 1 of the open issues changed in the seconds GitHub served the page, so a close and reopen while the later pages were read cannot be ruled out.', $this->failureOf($api)->getMessage());
    }

    public function testAnEarlierPageWhoseIssuesWereUpdatedBeforeTheClockSlackIsAccepted(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(1, 100, '2026-11-25T09:59:54Z'), 2 => $this->page(101, 1)];
        $api->namingNext = [1];
        $api->stable = true;

        self::assertCount(101, $api->reads()->listOpen('alpha', null)->issues());
    }

    public function testAnIssueUpdatedInTheSecondTheLastPageWasServedIsAccepted(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(1, 100), 2 => $this->page(101, 1, '2026-11-25T10:00:00Z')];
        $api->namingNext = [1];
        $api->stable = true;

        self::assertCount(101, $api->reads()->listOpen('alpha', null)->issues());
    }

    public function testAnEarlierPageWithoutADateFailsTheListing(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(1, 100), 2 => $this->page(101, 1)];
        $api->namingNext = [1];
        $api->stable = true;
        $api->served = null;

        self::assertSame('GitHub answered page 1 of open issues without a Date.', $this->failureOf($api)->getMessage());
    }

    public function testAnEarlierPageWithADateThisForgeCannotReadFailsTheListing(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(1, 100), 2 => $this->page(101, 1)];
        $api->namingNext = [1];
        $api->stable = true;
        $api->served = '2026-11-25T10:00:00Z';

        self::assertSame('GitHub answered a Date this forge cannot read.', $this->failureOf($api)->getMessage());
    }

    public function testAnEarlierPageWithADateWhoseSecondIsOutOfRangeFailsTheListing(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(1, 100, '2026-11-25T10:00:00Z'), 2 => $this->page(102, 1)];
        $api->namingNext = [1];
        $api->stable = true;
        $api->served = 'Wed, 25 Nov 2026 10:00:99 GMT';

        self::assertSame('GitHub answered a Date this forge cannot read.', $this->failureOf($api)->getMessage());
    }

    public function testAnEarlierPageWithADateWhoseWeekdayDoesNotMatchItsDayFailsTheListing(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(1, 100, '2026-11-25T10:00:00Z'), 2 => $this->page(102, 1)];
        $api->namingNext = [1];
        $api->stable = true;
        $api->served = 'Thu, 25 Nov 2026 10:00:00 GMT';

        self::assertSame('GitHub answered a Date this forge cannot read.', $this->failureOf($api)->getMessage());
    }

    public function testAListedIssueWhoseUpdateMomentIsNotWrittenAsGitHubWritesItFailsTheListing(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(1, 1, '2026-11-25T9:00:00Z')];

        self::assertSame('GitHub answered a moment this forge cannot read.', $this->failureOf($api)->getMessage());
    }

    public function testAListedIssueWhoseUpdateMomentIsOutOfRangeFailsTheListing(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(1, 1, '2026-11-25T09:00:99Z')];

        self::assertSame('GitHub answered a moment this forge cannot read.', $this->failureOf($api)->getMessage());
    }

    public function testASinglePageIsNotAskedAgain(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(1, 100)];

        $api->reads()->listOpen('alpha', null);

        self::assertCount(1, $api->requests);
    }

    public function testAListedIssueCarriesWhatTheIntakeReads(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => RecordedIssueApi::fixture('issues_open.json')];

        [$issue, $change] = $api->reads()->listOpen('alpha', null)->issues();

        self::assertSame(12, $issue->number);
        self::assertSame('Add the export', $issue->title);
        self::assertSame("Export the report.\n\nWaits for #9 and #10\n", $issue->body);
        self::assertSame(['intakt', 'priority:high'], $issue->labels);
        self::assertSame('ana', $issue->author);
        self::assertSame('2026-11-10T10:00:00+00:00', $issue->updatedAt->format('c'));
        self::assertSame('2026-12-01T08:00:00+00:00', $issue->milestoneDue?->format('c'));
        self::assertFalse($issue->isPullRequest);
        self::assertSame('', $change->body);
        self::assertNull($change->milestoneDue);
        self::assertTrue($change->isPullRequest);
    }

    public function testAFullPageWithoutANextLinkEndsTheListing(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(1, 100), 2 => $this->page(101, 100)];

        $listing = $api->reads()->listOpen('alpha', null);

        self::assertCount(100, $listing->issues());
        self::assertCount(1, $api->requests);
    }

    public function testASinglePageKeepsItsValidatorAndSeveralPagesHaveNone(): void
    {
        $single = new RecordedIssueApi();
        $single->pages = [1 => $this->page(1, 2)];
        $several = new RecordedIssueApi();
        $several->pages = [1 => $this->page(1, 100), 2 => $this->page(101, 1)];
        $several->namingNext = [1];
        $several->stable = true;

        self::assertSame('W/"issues-1"', $single->reads()->listOpen('alpha', null)->validator);
        self::assertNull($several->reads()->listOpen('alpha', null)->validator);
    }

    public function testAnUnchangedAnswerReadsNoPage(): void
    {
        $api = new RecordedIssueApi();
        $api->notModified = true;

        $listing = $api->reads()->listOpen('alpha', 'W/"issues-1"');

        self::assertTrue($listing->isUnchanged());
        self::assertSame(['W/"issues-1"'], $api->conditions);
    }

    public function testAnUnchangedAnswerWithoutAValidatorFailsTheListing(): void
    {
        $api = new RecordedIssueApi();
        $api->notModified = true;

        self::assertSame('GitHub answered 304 to a read without a validator.', $this->failureOf($api)->getMessage());
    }

    public function testAFailedLaterPageFailsTheWholeListing(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(1, 100), 2 => '500'];
        $api->namingNext = [1];

        self::assertStringContainsString('GitHub answered 500 to a read.', $this->failureOf($api)->getMessage());
    }

    public function testALaterPageAnsweredWithPartialContentFailsTheListing(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(1, 100), 2 => '206'];
        $api->namingNext = [1];
        $api->stable = true;

        self::assertSame('GitHub answered 206 to a read.', $this->failureOf($api)->getMessage());
    }

    public function testANextRelationWithSpacesAroundTheEqualsSignReadsTheNextPage(): void
    {
        self::assertCount(101, $this->listedWithNextLink('<https://api.github.com/repositories/1/issues?page=%d>; rel = "next"'));
    }

    public function testANextRelationAmongOthersInAQuotedValueReadsTheNextPage(): void
    {
        self::assertCount(101, $this->listedWithNextLink('<https://api.github.com/repositories/1/issues?page=%d>; rel="prev NEXT"'));
    }

    public function testAnUnquotedNextRelationReadsTheNextPage(): void
    {
        self::assertCount(101, $this->listedWithNextLink('<https://api.github.com/repositories/1/issues?page=9>; rel=last, <https://api.github.com/repositories/1/issues?page=%d>;rel=next'));
    }

    public function testANextRelationWrittenWithAQuotedPairReadsTheNextPage(): void
    {
        self::assertCount(101, $this->listedWithNextLink('<https://api.github.com/repositories/1/issues?page=%d>; rel="ne\xt"'));
    }

    public function testAQuotedPairThatSpellsAnotherRelationEndsTheListing(): void
    {
        self::assertCount(100, $this->listedWithNextLink('<https://api.github.com/repositories/1/issues?page=%d>; rel="next\-page"'));
    }

    public function testARelationThatOnlyStartsWithNextEndsTheListing(): void
    {
        self::assertCount(100, $this->listedWithNextLink('<https://api.github.com/repositories/1/issues?page=%d>; rel="next-page"'));
    }

    public function testANextRelationQuotedInsideAnotherParameterEndsTheListing(): void
    {
        self::assertCount(100, $this->listedWithNextLink('<https://api.github.com/repositories/1/issues?page=%d>; title="a; rel=next"'));
    }

    public function testANextRelationAnchoredToAnotherResourceEndsTheListing(): void
    {
        self::assertCount(100, $this->listedWithNextLink('<https://api.github.com/repositories/1/issues?page=%d>; rel="next"; anchor="https://api.github.com/repositories/2/issues"'));
    }

    public function testANextRelationWithAnAnchorParameterInAnotherCaseEndsTheListing(): void
    {
        self::assertCount(100, $this->listedWithNextLink('<https://api.github.com/repositories/1/issues?page=%d>; ANCHOR=#other; rel=next'));
    }

    public function testANextRelationWithAnEmptyAnchorBelongsToTheCurrentPageAndReadsTheNextPage(): void
    {
        self::assertCount(101, $this->listedWithNextLink('<https://api.github.com/repositories/1/issues?page=%d>; rel=next; anchor=""'));
    }

    public function testAnAnchorTextInsideAnotherParameterValueDoesNotDropTheNextRelation(): void
    {
        self::assertCount(101, $this->listedWithNextLink('<https://api.github.com/repositories/1/issues?page=%d>; title="x; anchor=y"; rel=next'));
    }

    public function testANextRelationNextToAnAnchoredLinkStillReadsTheNextPage(): void
    {
        self::assertCount(101, $this->listedWithNextLink('<https://api.github.com/repositories/2/issues>; rel="next"; anchor="#other", <https://api.github.com/repositories/1/issues?page=%d>; rel="next"'));
    }

    public function testALinkHeaderThisForgeCannotReadFailsTheListing(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(1, 100), 2 => $this->page(101, 1)];
        $api->namingNext = [1];
        $api->nextLink = '<https://api.github.com/repositories/1/issues?page=%d; rel="next"';

        self::assertSame('GitHub answered a Link header this forge cannot read.', $this->failureOf($api)->getMessage());
    }

    public function testARateLimitedLaterPageFailsTheListingAsRateLimited(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(1, 100), 2 => '429'];
        $api->namingNext = [1];

        $this->expectException(ForgeRateLimited::class);

        $api->reads()->listOpen('alpha', null);
    }

    public function testALaterPageThatIsNotAListFailsTheListing(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(1, 100), 2 => '{"message":"busy"}'];
        $api->namingNext = [1];

        self::assertStringContainsString('not a list', $this->failureOf($api)->getMessage());
    }

    public function testAnEmptyLaterPageThatStillNamesANextPageFailsTheListing(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(1, 100), 2 => '[]'];
        $api->namingNext = [1, 2];

        self::assertStringContainsString('short page 2', $this->failureOf($api)->getMessage());
    }

    public function testAShortPageThatStillNamesANextPageFailsTheListing(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(1, 40), 2 => $this->page(41, 100)];
        $api->namingNext = [1];

        self::assertStringContainsString('short page 1', $this->failureOf($api)->getMessage());
    }

    public function testReachingThePageCapWithANextLinkFailsTheListing(): void
    {
        $api = new RecordedIssueApi();
        foreach (range(1, 10) as $page) {
            $api->pages[$page] = $this->page(($page - 1) * 100 + 1, 100);
        }
        $api->namingNext = range(1, 10);

        self::assertStringContainsString('more open issues than this forge reads', $this->failureOf($api)->getMessage());
    }

    public function testTheLastAllowedPageWithoutANextLinkIsACompleteListing(): void
    {
        $api = new RecordedIssueApi();
        foreach (range(1, 10) as $page) {
            $api->pages[$page] = $this->page(($page - 1) * 100 + 1, 100);
        }
        $api->namingNext = range(1, 9);
        $api->stable = true;

        self::assertCount(1000, $api->reads()->listOpen('alpha', null)->issues());
    }

    /**
     * @return list<ListedIssue>
     */
    private function listedWithNextLink(string $nextLink): array
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(1, 100), 2 => $this->page(101, 1)];
        $api->namingNext = [1];
        $api->nextLink = $nextLink;
        $api->stable = true;

        return $api->reads()->listOpen('alpha', null)->issues();
    }

    private function failureOf(RecordedIssueApi $api): ForgeReadFailed
    {
        try {
            $api->reads()->listOpen('alpha', null);
        } catch (ForgeReadFailed $failed) {
            return $failed;
        }

        self::fail('The listing was expected to fail.');
    }

    private function page(int $from, int $count, string $updatedAt = '2026-11-10T10:00:00Z'): string
    {
        $issues = array_map(static fn (int $number): array => [
            'number' => $number,
            'title' => 'Issue '.$number,
            'body' => '',
            'user' => ['login' => 'ana'],
            'labels' => [],
            'milestone' => null,
            'updated_at' => $updatedAt,
        ], range($from, $from + $count - 1));

        return json_encode($issues, JSON_THROW_ON_ERROR);
    }
}
