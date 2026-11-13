<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ForgeCredentialsRefused;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\InstallationToken;
use Intakt\Plugin\GitHubForge\Contract\IssueListing;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Domain\Installation;
use Intakt\Plugin\GitHubForge\Domain\RateLimitReading;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\BudgetReserve;
use Intakt\Plugin\GitHubForge\Infrastructure\CheckedInstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueHistories;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueReads;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubMoments;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReads;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\RateLimitReadings;
use Intakt\Plugin\GitHubForge\Infrastructure\RepositoryAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\WatchedRepositories;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitHubIssueReads::class)]
#[CoversClass(InstallationReads::class)]
#[UsesClass(GitHubIssueHistories::class)]
#[UsesClass(IssueListing::class)]
#[UsesClass(ListedIssue::class)]
#[UsesClass(ForgeReadFailed::class)]
#[UsesClass(ForgeRateLimited::class)]
#[UsesClass(ForgeCredentialsRefused::class)]
#[UsesClass(GitHubMoments::class)]
#[UsesClass(WatchedRepositories::class)]
#[UsesClass(RepositoryAddress::class)]
#[UsesClass(BudgetReserve::class)]
#[UsesClass(RateLimitReadings::class)]
#[UsesClass(RateLimitReading::class)]
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
final class GitHubIssueReadsTest extends TestCase
{
    private RecordedIssueApi $api;

    protected function setUp(): void
    {
        $this->api = new RecordedIssueApi();
    }

    public function testThreeFullPagesAreReadInOrder(): void
    {
        $this->api->pages = [1 => $this->page(1, 100), 2 => $this->page(101, 100), 3 => $this->page(201, 49)];

        $listing = $this->api->reads()->listOpen('alpha', null);

        self::assertSame(range(1, 249), array_map(static fn (ListedIssue $issue): int => $issue->number, $listing->issues()));
        self::assertSame([1, 2, 3, 1, 2], $this->pagesRequested());
    }

    public function testAnIssueClosedWhileTheListingIsReadFailsTheListing(): void
    {
        $this->api->pages = [1 => $this->page(1, 100), 2 => $this->page(101, 50)];
        // Issue 1 is closed after the first page was read, so issue 101 moves onto the page already read.
        $this->api->movedPages = [1 => $this->page(2, 100), 2 => $this->page(102, 49)];
        $this->api->movesAfter = 1;

        $this->expectExceptionObject(new ForgeReadFailed('GitHub moved an issue between pages while the listing was read.'));

        $this->api->reads()->listOpen('alpha', null);
    }

    public function testAnIssueOpenedWhileTheListingIsReadFailsTheListing(): void
    {
        $this->api->pages = [1 => $this->page(2, 100), 2 => $this->page(102, 50)];
        // Issue 1 is opened after both pages were read, so the earlier page no longer holds what was read from it.
        $this->api->movedPages = [1 => $this->page(1, 100), 2 => $this->page(101, 51)];
        $this->api->movesAfter = 2;

        $this->expectExceptionObject(new ForgeReadFailed('GitHub moved an issue between pages while the listing was read.'));

        $this->api->reads()->listOpen('alpha', null);
    }

    public function testAnIssueEditedWhileTheListingIsReadKeepsTheListing(): void
    {
        $this->api->pages = [1 => $this->page(1, 100), 2 => $this->page(101, 50)];
        $this->api->movedPages = [1 => str_replace('Task 7"', 'Task seven"', $this->page(1, 100)), 2 => $this->page(101, 50)];
        $this->api->movesAfter = 2;

        self::assertCount(150, $this->api->reads()->listOpen('alpha', null)->issues());
    }

    public function testAnEarlierPageThatFailsWhenReadAgainFailsTheListing(): void
    {
        $this->api->pages = [1 => $this->page(1, 100), 2 => $this->page(101, 50)];
        $this->api->movedPages = [1 => '502', 2 => $this->page(101, 50)];
        $this->api->movesAfter = 2;

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered 502 to a read.'));

        $this->api->reads()->listOpen('alpha', null);
    }

    public function testAnIssueIsReadFromTheRecordedListing(): void
    {
        $this->api->pages = [1 => RecordedIssueApi::fixture('issues_open.json')];

        $listing = $this->api->reads()->listOpen('alpha', null);

        [$task, $pull] = $listing->issues();
        self::assertSame([12, 'Add the export', ['intakt', 'priority:high'], 'ana', false], [$task->number, $task->title, $task->labels, $task->author, $task->isPullRequest]);
        self::assertSame('2026-12-01T08:00:00+00:00', $task->milestoneDue?->format('c'));
        self::assertSame([13, '', true, '2026-11-10T09:30:00+00:00'], [$pull->number, $pull->body, $pull->isPullRequest, $pull->updatedAt->format('c')]);
        self::assertSame('W/"issues-1"', $listing->validator);
    }

    public function testAListingOfSeveralPagesHasNoValidator(): void
    {
        $this->api->pages = [1 => $this->page(1, 100), 2 => $this->page(101, 2)];

        self::assertNull($this->api->reads()->listOpen('alpha', null)->validator);
    }

    public function testAnUnchangedListingIsAnsweredWithoutIssues(): void
    {
        $this->api->notModified = true;

        $listing = $this->api->reads()->listOpen('alpha', 'W/"issues-1"');

        self::assertTrue($listing->isUnchanged());
        self::assertSame(['W/"issues-1"'], $this->api->conditions);
    }

    public function testALaterPageThatFailsFailsTheListing(): void
    {
        $this->api->pages = [1 => $this->page(1, 100), 2 => '502'];

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered 502 to a read.'));

        $this->api->reads()->listOpen('alpha', null);
    }

    public function testALaterPageThatIsRateLimitedFailsTheListing(): void
    {
        $this->api->pages = [1 => $this->page(1, 100), 2 => '429'];

        $this->expectException(ForgeRateLimited::class);

        $this->api->reads()->listOpen('alpha', null);
    }

    public function testALaterPageThatIsNotAListFailsTheListing(): void
    {
        $this->api->pages = [1 => $this->page(1, 100), 2 => '{}'];

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered a listing that is not a list.'));

        $this->api->reads()->listOpen('alpha', null);
    }

    public function testAnEmptyPageThatNamesANextPageFailsTheListing(): void
    {
        $this->api->pages = [1 => $this->page(1, 100), 2 => '[]', 3 => $this->page(201, 1)];

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered a short page before the last one.'));

        $this->api->reads()->listOpen('alpha', null);
    }

    public function testAShortPageThatNamesANextPageFailsTheListing(): void
    {
        $this->api->pages = [1 => $this->page(1, 99), 2 => $this->page(100, 1)];

        $this->expectExceptionObject(new ForgeReadFailed('GitHub answered a short page before the last one.'));

        $this->api->reads()->listOpen('alpha', null);
    }

    public function testAListingThatReachesThePageCapFails(): void
    {
        $this->api->pages = $this->fullPages(11);

        try {
            $this->api->reads()->listOpen('alpha', null);
            self::fail('A listing past the page cap was answered.');
        } catch (ForgeReadFailed $failure) {
            self::assertSame('GitHub lists more open issues than this forge reads.', $failure->getMessage());
            self::assertSame(range(1, 10), $this->pagesRequested());
        }
    }

    public function testAListingOfExactlyThePageCapIsRead(): void
    {
        $this->api->pages = $this->fullPages(9);
        $this->api->pages[10] = $this->page(901, 1);

        self::assertCount(901, $this->api->reads()->listOpen('alpha', null)->issues());
    }

    public function testAnIssueListedOnTwoPagesFailsTheListing(): void
    {
        $this->api->pages = [1 => $this->page(1, 100), 2 => $this->page(100, 50)];

        $this->expectExceptionObject(new ForgeReadFailed('GitHub listed an issue twice, so the listing moved while it was read.'));

        $this->api->reads()->listOpen('alpha', null);
    }

    public function testAFullPageFollowedByAnEmptyOneEndsTheListing(): void
    {
        $this->api->pages = [1 => $this->page(1, 100), 2 => '[]'];
        $this->api->links = false;

        self::assertCount(100, $this->api->reads()->listOpen('alpha', null)->issues());
        self::assertSame([1, 2, 1], $this->pagesRequested());
    }

    public function testAFullPageWithoutANextLinkIsReadOnUntilAShortPage(): void
    {
        $this->api->links = false;
        $this->api->pages = [1 => $this->page(1, 100), 2 => $this->page(101, 2)];

        self::assertCount(102, $this->api->reads()->listOpen('alpha', null)->issues());
    }

    /**
     * @return list<int>
     */
    private function pagesRequested(): array
    {
        $pages = [];
        foreach ($this->api->requests as $request) {
            if (1 === preg_match('/\/issues\?.*\bpage=(\d+)/', $request, $found)) {
                $pages[] = (int) $found[1];
            }
        }

        return $pages;
    }

    /**
     * @return array<int, string>
     */
    private function fullPages(int $count): array
    {
        $pages = [];
        foreach (range(1, $count) as $page) {
            $pages[$page] = $this->page(($page - 1) * 100 + 1, 100);
        }

        return $pages;
    }

    private function page(int $first, int $count): string
    {
        $issues = [];
        for ($number = $first; $number < $first + $count; ++$number) {
            $issues[] = ['number' => $number, 'title' => 'Task '.$number, 'body' => '', 'user' => ['login' => 'ana'], 'labels' => [['name' => 'intakt']], 'milestone' => null, 'updated_at' => '2026-11-10T10:00:00Z'];
        }

        return json_encode($issues, JSON_THROW_ON_ERROR);
    }
}
