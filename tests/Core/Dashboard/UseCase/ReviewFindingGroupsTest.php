<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\FindingUrgency;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;

// The main view's findings to fix below the verdict card: one group per urgency, one row per finding (owner decision of 2026-11-22, #3573).
#[CoversClass(ShowReviewController::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(RoundFinding::class)]
#[UsesClass(FindingUrgency::class)]
final class ReviewFindingGroupsTest extends DashboardPageTestCase
{
    public function testTheFindingsToFixAreGroupedAsUrgentShouldFixAndNiceToHaveInThatOrder(): void
    {
        $this->givenFindingsToFixRated(['F1' => 'P3', 'F2' => 'P1', 'F3' => 'P2', 'F4' => 'P1']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(
            [['Urgent', '2', ['Text of F2.', 'Text of F4.']], ['Should fix', '1', ['Text of F3.']], ['Nice to have', '1', ['Text of F1.']]],
            $this->groupsOf($crawler),
        );
    }

    public function testEachRowShowsTheHeadlineAndThePlaceButNoExplanation(): void
    {
        $this->givenAChangesRound();
        $this->ledger->judgeToFixAtLine7OfA('round-1', 'The total is off by one. It drops the last item.');

        $row = $this->browser->request('GET', '/reviews/round-1')->filter('#review-to-fix .fix-row');

        self::assertSame(['The total is off by one.', 'src/A.php:7', 0], [$row->filter('.fix-title')->text(), $row->filter('.fix-place')->text(), $row->filter('.fix-more')->count()]);
    }

    public function testARowKeepsTheSeverityInItsTooltip(): void
    {
        $this->givenFindingsToFixRated(['F1' => 'P2']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame('P2', $crawler->filter('#review-to-fix .fix-row')->attr('title'));
    }

    public function testAnUrgencyWithoutFindingsKeepsItsGroupAndSaysNone(): void
    {
        $this->givenFindingsToFixRated(['F1' => 'P1']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(['', 'None', 'None'], $crawler->filter('#review-to-fix .fix-group')->each(static fn (Crawler $group): string => $group->filter('.fix-none')->count() > 0 ? $group->filter('.fix-none')->text() : ''));
    }

    public function testAFindingWithoutARecordedUrgencyIsListedInAFourthGroupSoNoneIsHidden(): void
    {
        $this->givenFindingsToFixRated(['F1' => null, 'F2' => 'P2']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $last = $crawler->filter('#review-to-fix .fix-group')->last();

        self::assertSame(['Urgency not recorded', '1', 1], [$last->filter('.fix-group-name')->text(), $last->filter('.fix-group-count')->text(), $last->filter('.fix-row')->count()]);
    }

    public function testFindingsThatAllHaveAnUrgencyGetNoFourthGroup(): void
    {
        $this->givenFindingsToFixRated(['F1' => 'P2']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(['Urgent', 'Should fix', 'Nice to have'], array_column($this->groupsOf($crawler), 0));
    }

    public function testNothingInTheGroupsFoldsAndARowCarriesNoKindSymbolWhileNoKindIsRecorded(): void
    {
        $this->givenFindingsToFixRated(['F1' => 'P1', 'F2' => 'P3']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame([0, 0], [$crawler->filter('#review-to-fix details')->count(), $crawler->filter('#review-to-fix .fix-row svg:not(.fix-chev)')->count()]);
    }

    public function testEachRowOpensItsFindingInTheFullRecord(): void
    {
        $this->givenFindingsToFixRated(['F1' => 'P3', 'F2' => 'P1']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertSame(['/reviews/round-1/record#round-round-1-finding-F2', '/reviews/round-1/record#round-round-1-finding-F1'], $crawler->filter('#review-to-fix a.fix-row')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
    }

    public function testTheFullRecordLinkStaysBelowTheGroups(): void
    {
        $this->givenFindingsToFixRated(['F1' => 'P1']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        self::assertCount(1, $crawler->filterXPath('//section[@id="review-to-fix"]/following-sibling::a[contains(@class, "full-record")]'));
    }

    // Each group is labelled by its own heading, so a screen reader lists Urgent, Should fix and Nice to have as the page's sections.
    public function testEachGroupIsASectionNamedByItsHeading(): void
    {
        $this->givenFindingsToFixRated(['F1' => 'P1']);

        $crawler = $this->browser->request('GET', '/reviews/round-1');

        $groups = $crawler->filter('#review-to-fix section.fix-group');

        self::assertSame(['fix-group-urgent', 'fix-group-should-fix', 'fix-group-nice-to-have'], $groups->each(static fn (Crawler $group): ?string => $group->attr('aria-labelledby')));
        self::assertSame($groups->each(static fn (Crawler $group): ?string => $group->attr('aria-labelledby')), $groups->each(static fn (Crawler $group): ?string => $group->filter('h2')->attr('id')));
    }

    /**
     * @param array<string, ?string> $severities
     */
    private function givenFindingsToFixRated(array $severities): void
    {
        $this->givenAChangesRound();
        $this->ledger->judgeRatedFindings('round-1', $severities);
    }

    private function givenAChangesRound(): void
    {
        $this->ledger->startRoundOfQuorum('feat/thing', 'round-1', 'all');
        $this->ledger->answer('round-1', 'correctness', 1);
        $this->ledger->answer('round-1', 'security', 0);
    }

    /**
     * @return list<array{string, string, list<string>}>
     */
    private function groupsOf(Crawler $page): array
    {
        return $page->filter('#review-to-fix .fix-group')->each(static fn (Crawler $group): array => [
            $group->filter('.fix-group-name')->text(),
            $group->filter('.fix-group-count')->text(),
            $group->filter('.fix-title')->each(static fn (Crawler $title): string => $title->text()),
        ]);
    }
}
