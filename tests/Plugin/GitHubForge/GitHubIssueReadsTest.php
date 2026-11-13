<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueListing;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueReads;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubMoments;
use Intakt\Plugin\GitHubForge\Infrastructure\RepositoryAddress;
use Intakt\Plugin\GitHubForge\Infrastructure\WatchedRepositories;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitHubIssueReads::class)]
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

        $listing = $api->reads()->listOpen('alpha', null);

        self::assertSame(range(1, 300), array_map(static fn (ListedIssue $issue): int => $issue->number, $listing->issues()));
        self::assertCount(3, $api->requests);
        self::assertStringContainsString('page=1', $api->requests[0]);
        self::assertStringContainsString('page=2', $api->requests[1]);
        self::assertStringContainsString('page=3', $api->requests[2]);
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

    public function testAFailedLaterPageFailsTheWholeListing(): void
    {
        $api = new RecordedIssueApi();
        $api->pages = [1 => $this->page(1, 100), 2 => '500'];
        $api->namingNext = [1];

        self::assertStringContainsString('GitHub answered 500 to a read.', $this->failureOf($api)->getMessage());
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

        self::assertCount(1000, $api->reads()->listOpen('alpha', null)->issues());
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

    private function page(int $from, int $count): string
    {
        $issues = array_map(static fn (int $number): array => [
            'number' => $number,
            'title' => 'Issue '.$number,
            'body' => '',
            'user' => ['login' => 'ana'],
            'labels' => [],
            'milestone' => null,
            'updated_at' => '2026-11-10T10:00:00Z',
        ], range($from, $from + $count - 1));

        return json_encode($issues, JSON_THROW_ON_ERROR);
    }
}
