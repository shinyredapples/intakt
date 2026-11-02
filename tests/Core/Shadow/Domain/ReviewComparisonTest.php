<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Shadow\Domain;

use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\RoundTokens;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Shadow\Contract\FindingMatch;
use Intakt\Core\Shadow\Contract\HeadComparison;
use Intakt\Core\Shadow\Contract\LoopFinding;
use Intakt\Core\Shadow\Contract\LoopReview;
use Intakt\Core\Shadow\Contract\LoopReviews;
use Intakt\Core\Shadow\Contract\LoopVerdict;
use Intakt\Core\Shadow\Contract\ReviewerDecision;
use Intakt\Core\Shadow\Contract\ReviewOutcome;
use Intakt\Core\Shadow\Domain\MergedSpot;
use Intakt\Core\Shadow\Domain\ReviewComparison;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewComparison::class)]
#[CoversClass(MergedSpot::class)]
#[CoversClass(HeadComparison::class)]
#[CoversClass(FindingMatch::class)]
#[UsesClass(ReviewerDecision::class)]
#[UsesClass(LoopReviews::class)]
#[UsesClass(LoopReview::class)]
#[UsesClass(LoopFinding::class)]
#[UsesClass(ChangeReview::class)]
#[UsesClass(PanelVerdict::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(Finding::class)]
#[UsesClass(RoundTokens::class)]
final class ReviewComparisonTest extends TestCase
{
    public function testAFindingOnTheSameFileWithinTheLineWindowIsMatched(): void
    {
        $loop = ComparisonBuilder::loop(ComparisonBuilder::loopFinding('src/A.php', 10));
        $round = ComparisonBuilder::round(ComparisonBuilder::changes(ComparisonBuilder::finding('src/A.php', 10 + ReviewComparison::LINE_WINDOW)));

        $result = new ReviewComparison()->compare('h', $loop, [$round]);

        self::assertCount(1, $result->matches);
        self::assertSame([], $result->loopOnly);
        self::assertSame([], $result->intaktOnly);
    }

    public function testAFindingOutsideTheLineWindowIsRaisedByOneSideOnly(): void
    {
        $loop = ComparisonBuilder::loop(ComparisonBuilder::loopFinding('src/A.php', 10));
        $round = ComparisonBuilder::round(ComparisonBuilder::changes(ComparisonBuilder::finding('src/A.php', 11 + ReviewComparison::LINE_WINDOW)));

        $result = new ReviewComparison()->compare('h', $loop, [$round]);

        self::assertSame([0, 1, 1], [count($result->matches), count($result->loopOnly), count($result->intaktOnly)]);
    }

    public function testAFindingInAnotherFileIsNeverMatched(): void
    {
        $loop = ComparisonBuilder::loop(ComparisonBuilder::loopFinding('src/A.php', 10));
        $round = ComparisonBuilder::round(ComparisonBuilder::changes(ComparisonBuilder::finding('src/B.php', 10)));

        $result = new ReviewComparison()->compare('h', $loop, [$round]);

        self::assertCount(0, $result->matches);
    }

    public function testTheSameSpotRaisedByTwoReviewersOnOneSideIsOneFinding(): void
    {
        $loop = ComparisonBuilder::loop(ComparisonBuilder::loopFinding('src/A.php', 10));
        $round = ComparisonBuilder::round(ComparisonBuilder::changes(ComparisonBuilder::finding('src/A.php', 10)));

        $result = new ReviewComparison()->compare('h', $loop, [$round, $round]);

        self::assertSame([1, 0, 0], [count($result->matches), count($result->loopOnly), count($result->intaktOnly)]);
    }

    public function testTheSameLoopFindingRaisedByTwoLoopReviewersIsOneFinding(): void
    {
        $finding = ComparisonBuilder::loopFinding('src/A.php', 10);
        $loop = new LoopReviews([new LoopReview('a', LoopVerdict::Changes, [$finding]), new LoopReview('b', LoopVerdict::Changes, [$finding])]);
        $round = ComparisonBuilder::round(ComparisonBuilder::changes(ComparisonBuilder::finding('src/A.php', 10)));

        $result = new ReviewComparison()->compare('h', $loop, [$round]);

        self::assertSame([1, 0, 0], [count($result->matches), count($result->loopOnly), count($result->intaktOnly)]);
    }

    public function testTheSameSpotAtTwoSeveritiesFromTwoIntaktReviewersIsOneFindingAtTheStricterSeverity(): void
    {
        $loop = ComparisonBuilder::loop(ComparisonBuilder::loopFinding('src/A.php', 10, Severity::P2));
        $p2 = ComparisonBuilder::round(ComparisonBuilder::changes(ComparisonBuilder::finding('src/A.php', 10, Severity::P2)));
        $p1 = ComparisonBuilder::round(ComparisonBuilder::changes(ComparisonBuilder::finding('src/A.php', 10, Severity::P1)));

        $result = new ReviewComparison()->compare('h', $loop, [$p2, $p1]);

        self::assertSame([1, 0, 0], [count($result->matches), count($result->loopOnly), count($result->intaktOnly)]);
        self::assertSame(Severity::P1, $result->matches[0]->intakt->severity);
        self::assertSame(1, $result->severityDisagreements());
    }

    public function testTwoIntaktReviewersWithinTheLineWindowOfEachOtherRaiseOneFinding(): void
    {
        $loop = ComparisonBuilder::loop(ComparisonBuilder::loopFinding('src/A.php', 10));
        $a = ComparisonBuilder::round(ComparisonBuilder::changes(ComparisonBuilder::finding('src/A.php', 10)));
        $b = ComparisonBuilder::round(ComparisonBuilder::changes(ComparisonBuilder::finding('src/A.php', 11)));

        $result = new ReviewComparison()->compare('h', $loop, [$a, $b]);

        self::assertSame([1, 0, 0], [count($result->matches), count($result->loopOnly), count($result->intaktOnly)]);
    }

    public function testTheSameSpotAtTwoSeveritiesFromTwoLoopReviewersIsOneFindingAtTheStricterSeverity(): void
    {
        $loop = new LoopReviews([
            new LoopReview('a', LoopVerdict::Changes, [ComparisonBuilder::loopFinding('src/A.php', 10, Severity::P3)]),
            new LoopReview('b', LoopVerdict::Changes, [ComparisonBuilder::loopFinding('src/A.php', 10, Severity::P1)]),
        ]);
        $round = ComparisonBuilder::round(ComparisonBuilder::changes(ComparisonBuilder::finding('src/A.php', 10, Severity::P1)));

        $result = new ReviewComparison()->compare('h', $loop, [$round]);

        self::assertSame([1, 0, 0], [count($result->matches), count($result->loopOnly), count($result->intaktOnly)]);
        self::assertSame(Severity::P1, $result->matches[0]->loop->severity);
        self::assertSame(0, $result->severityDisagreements());
    }

    public function testTwoLoopReviewersWithinTheLineWindowOfEachOtherRaiseOneFinding(): void
    {
        $loop = new LoopReviews([
            new LoopReview('a', LoopVerdict::Changes, [ComparisonBuilder::loopFinding('src/A.php', 10)]),
            new LoopReview('b', LoopVerdict::Changes, [ComparisonBuilder::loopFinding('src/A.php', 10 + ReviewComparison::LINE_WINDOW)]),
        ]);
        $round = ComparisonBuilder::round(ComparisonBuilder::changes(ComparisonBuilder::finding('src/A.php', 10)));

        $result = new ReviewComparison()->compare('h', $loop, [$round]);

        self::assertSame([1, 0, 0], [count($result->matches), count($result->loopOnly), count($result->intaktOnly)]);
    }

    public function testTwoFindingsOnOneSideFurtherApartThanTheLineWindowStayTwo(): void
    {
        $loop = ComparisonBuilder::loop(ComparisonBuilder::loopFinding('src/A.php', 10));
        $round = ComparisonBuilder::round(ComparisonBuilder::changes(
            ComparisonBuilder::finding('src/A.php', 10),
            ComparisonBuilder::finding('src/A.php', 11 + ReviewComparison::LINE_WINDOW),
        ));

        $result = new ReviewComparison()->compare('h', $loop, [$round]);

        self::assertSame([1, 0, 1], [count($result->matches), count($result->loopOnly), count($result->intaktOnly)]);
    }

    public function testAStricterFindingJoiningAMergedSpotKeepsTheEarlierLineForMatching(): void
    {
        $loop = ComparisonBuilder::loop(ComparisonBuilder::loopFinding('src/A.php', 7));
        $p2 = ComparisonBuilder::round(ComparisonBuilder::changes(ComparisonBuilder::finding('src/A.php', 10, Severity::P2)));
        $p1 = ComparisonBuilder::round(ComparisonBuilder::changes(ComparisonBuilder::finding('src/A.php', 13, Severity::P1)));

        $result = new ReviewComparison()->compare('h', $loop, [$p2, $p1]);

        self::assertSame([1, 0, 0], [count($result->matches), count($result->loopOnly), count($result->intaktOnly)]);
        self::assertSame(Severity::P1, $result->matches[0]->intakt->severity);
    }

    public function testAMergedSpotWhoseStricterFindingIsOutsideTheWindowStillMatchesByItsNearerLine(): void
    {
        $loop = ComparisonBuilder::loop(ComparisonBuilder::loopFinding('src/A.php', 5));
        $p3 = ComparisonBuilder::round(ComparisonBuilder::changes(ComparisonBuilder::finding('src/A.php', 7, Severity::P3)));
        $p1 = ComparisonBuilder::round(ComparisonBuilder::changes(ComparisonBuilder::finding('src/A.php', 10, Severity::P1)));

        $result = new ReviewComparison()->compare('h', $loop, [$p3, $p1]);

        self::assertSame([1, 0, 0], [count($result->matches), count($result->loopOnly), count($result->intaktOnly)]);
    }

    public function testFindingsOfOneReviewNeverMergeSoEachMatchesItsOwnLoopFinding(): void
    {
        $loop = ComparisonBuilder::loop(ComparisonBuilder::loopFinding('src/A.php', 8), ComparisonBuilder::loopFinding('src/A.php', 15));
        $round = ComparisonBuilder::round(ComparisonBuilder::changes(
            ComparisonBuilder::finding('src/A.php', 10),
            ComparisonBuilder::finding('src/A.php', 13),
        ));

        $result = new ReviewComparison()->compare('h', $loop, [$round]);

        self::assertSame([2, 0, 0], [count($result->matches), count($result->loopOnly), count($result->intaktOnly)]);
    }

    public function testNearbyFindingsOfOneReviewStaySeparateFindings(): void
    {
        $round = ComparisonBuilder::round(ComparisonBuilder::changes(
            ComparisonBuilder::finding('src/A.php', 10),
            ComparisonBuilder::finding('src/A.php', 13),
            ComparisonBuilder::finding('src/A.php', 16),
            ComparisonBuilder::finding('src/A.php', 19),
        ));

        $result = new ReviewComparison()->compare('h', ComparisonBuilder::loop(), [$round]);

        self::assertCount(4, $result->intaktOnly);
    }

    public function testFindingsOfSeveralReviewersMergeNoWiderThanTheLineWindow(): void
    {
        $rounds = array_map(
            static fn (int $line): ChangeReview => ComparisonBuilder::round(ComparisonBuilder::changes(ComparisonBuilder::finding('src/A.php', $line))),
            [10, 13, 16, 19],
        );

        $result = new ReviewComparison()->compare('h', ComparisonBuilder::loop(), $rounds);

        self::assertCount(2, $result->intaktOnly);
    }

    public function testFindingsWithoutAFileFromTwoReviewersNeverMerge(): void
    {
        $a = ComparisonBuilder::round(ComparisonBuilder::changes(ComparisonBuilder::finding(null, 10)));
        $b = ComparisonBuilder::round(ComparisonBuilder::changes(ComparisonBuilder::finding(null, 11)));

        $result = new ReviewComparison()->compare('h', ComparisonBuilder::loop(), [$a, $b]);

        self::assertCount(2, $result->intaktOnly);
    }

    public function testAMergedLoopSpotMatchesByItsNearestLine(): void
    {
        $loop = new LoopReviews([
            new LoopReview('a', LoopVerdict::Changes, [ComparisonBuilder::loopFinding('src/A.php', 10)]),
            new LoopReview('b', LoopVerdict::Changes, [ComparisonBuilder::loopFinding('src/A.php', 13)]),
        ]);
        $round = ComparisonBuilder::round(ComparisonBuilder::changes(ComparisonBuilder::finding('src/A.php', 16)));

        $result = new ReviewComparison()->compare('h', $loop, [$round]);

        self::assertSame([1, 0, 0], [count($result->matches), count($result->loopOnly), count($result->intaktOnly)]);
    }

    public function testFindingsOnOneSideMergeTheSameWhateverTheirOrder(): void
    {
        $a = ComparisonBuilder::round(ComparisonBuilder::changes(ComparisonBuilder::finding('src/A.php', 10, Severity::P2)));
        $b = ComparisonBuilder::round(ComparisonBuilder::changes(ComparisonBuilder::finding('src/A.php', 16, Severity::P3)));
        $c = ComparisonBuilder::round(ComparisonBuilder::changes(ComparisonBuilder::finding('src/A.php', 12, Severity::P1)));
        $comparison = new ReviewComparison();

        $first = $comparison->compare('h', ComparisonBuilder::loop(), [$a, $b, $c]);
        $second = $comparison->compare('h', ComparisonBuilder::loop(), [$c, $b, $a]);

        $severities = static fn (HeadComparison $result): array => array_map(static fn (Finding $finding): Severity => $finding->severity, $result->intaktOnly);
        self::assertSame([Severity::P1, Severity::P3], $severities($first));
        self::assertSame([Severity::P1, Severity::P3], $severities($second));
    }

    public function testFindingsOfTwoReviewsOnTheSameLineMergeTheSameWhateverTheReviewersOrder(): void
    {
        $a = ComparisonBuilder::round(ComparisonBuilder::changes(ComparisonBuilder::finding('src/A.php', 10), ComparisonBuilder::finding('src/A.php', 12)));
        $b = ComparisonBuilder::round(ComparisonBuilder::changes(ComparisonBuilder::finding('src/A.php', 12), ComparisonBuilder::finding('src/A.php', 14)));
        $comparison = new ReviewComparison();

        $first = $comparison->compare('h', ComparisonBuilder::loop(), [$a, $b]);
        $second = $comparison->compare('h', ComparisonBuilder::loop(), [$b, $a]);

        self::assertSame([2, 2], [count($first->intaktOnly), count($second->intaktOnly)]);
    }

    public function testAnIntaktFindingMatchesOnlyOneLoopFinding(): void
    {
        $loop = ComparisonBuilder::loop(ComparisonBuilder::loopFinding('src/A.php', 10), ComparisonBuilder::loopFinding('src/A.php', 11 + ReviewComparison::LINE_WINDOW));
        $round = ComparisonBuilder::round(ComparisonBuilder::changes(ComparisonBuilder::finding('src/A.php', 10 + ReviewComparison::LINE_WINDOW)));

        $result = new ReviewComparison()->compare('h', $loop, [$round]);

        self::assertCount(1, $result->matches);
        self::assertCount(1, $result->loopOnly);
    }

    public function testAMatchWithDifferentSeveritiesIsASeverityDisagreement(): void
    {
        $loop = ComparisonBuilder::loop(ComparisonBuilder::loopFinding('src/A.php', 10, Severity::P1), ComparisonBuilder::loopFinding('src/B.php', 5, Severity::P2));
        $round = ComparisonBuilder::round(ComparisonBuilder::changes(
            ComparisonBuilder::finding('src/A.php', 10, Severity::P3),
            ComparisonBuilder::finding('src/B.php', 5, Severity::P2),
        ));

        $result = new ReviewComparison()->compare('h', $loop, [$round]);

        self::assertCount(2, $result->matches);
        self::assertSame(1, $result->severityDisagreements());
    }

    public function testAnUnreadableLoopReviewIsCountedAsUnreadableAndNeverAsAgreement(): void
    {
        $loop = new LoopReviews([new LoopReview('security', LoopVerdict::Unreadable, [])]);
        $round = ComparisonBuilder::round(new Verdict(Decision::Approve, [], [], []));

        $result = new ReviewComparison()->compare('h', $loop, [$round]);

        self::assertSame(1, $result->unreadableLoopReviews());
        self::assertSame(ReviewOutcome::Unreadable, $result->loopDecisions[0]->outcome);
        self::assertSame(ReviewOutcome::Approve, $result->intaktDecisions[0]->outcome);
    }

    public function testAnIntaktFailedReviewIsCountedAsFailedNotAsChanges(): void
    {
        $round = ComparisonBuilder::round(Verdict::invalidOutput('not json'));

        $result = new ReviewComparison()->compare('h', new LoopReviews([]), [$round]);

        self::assertSame(1, $result->intaktFailedReviews());
        self::assertSame(ReviewOutcome::Failed, $result->intaktDecisions[0]->outcome);
        self::assertSame([], $result->intaktOnly);
    }

    public function testAnIntaktFindingWithoutAFileOrLineStaysIntaktOnly(): void
    {
        $loop = ComparisonBuilder::loop(ComparisonBuilder::loopFinding('src/A.php', 10));
        $round = ComparisonBuilder::round(ComparisonBuilder::changes(ComparisonBuilder::finding(null, null), ComparisonBuilder::finding('src/A.php', null, Severity::P2)));

        $result = new ReviewComparison()->compare('h', $loop, [$round]);

        self::assertCount(0, $result->matches);
        self::assertCount(2, $result->intaktOnly);
    }

    public function testEachSidesApproveOrChangesIsReported(): void
    {
        $loop = new LoopReviews([new LoopReview('style', LoopVerdict::Approve, []), new LoopReview('docs', LoopVerdict::Changes, [])]);
        $round = ComparisonBuilder::round(ComparisonBuilder::changes(), 250);

        $result = new ReviewComparison()->compare('h', $loop, [$round]);

        self::assertSame([ReviewOutcome::Approve, ReviewOutcome::Changes], array_map(static fn (ReviewerDecision $d): ReviewOutcome => $d->outcome, $result->loopDecisions));
        self::assertSame(ReviewOutcome::Changes, $result->intaktDecisions[0]->outcome);
        self::assertSame(250, $result->intaktTokens);
        self::assertSame(1, $result->intaktReviews());
    }
}
