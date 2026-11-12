<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\ReviewerForLoop\Domain;

use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\ReviewerForLoop\Contract\LoopFinding;
use Intakt\Core\ReviewerForLoop\Contract\LoopReview;
use Intakt\Core\ReviewerForLoop\Contract\LoopReviews;
use Intakt\Core\ReviewerForLoop\Contract\LoopVerdict;
use Intakt\Core\ReviewerForLoop\Domain\MergedSpot;
use Intakt\Core\ReviewerForLoop\Domain\ReviewComparison;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewComparison::class)]
#[CoversClass(MergedSpot::class)]
#[UsesClass(LoopReviews::class)]
#[UsesClass(LoopReview::class)]
#[UsesClass(LoopFinding::class)]
final class ReviewComparisonTest extends TestCase
{
    public function testAFindingOnTheSameFileWithinTheLineWindowIsMatched(): void
    {
        $loop = ComparisonBuilder::loop(ComparisonBuilder::loopFinding('src/A.php', 10));
        $round = ComparisonBuilder::round(ComparisonBuilder::finding('src/A.php', 10 + ReviewComparison::LINE_WINDOW));

        $result = $this->spots($loop, [$round]);

        self::assertCount(1, $result['matches']);
        self::assertSame([], $result['loopOnly']);
        self::assertSame([], $result['intaktOnly']);
    }

    public function testAFindingOutsideTheLineWindowIsRaisedByOneSideOnly(): void
    {
        $loop = ComparisonBuilder::loop(ComparisonBuilder::loopFinding('src/A.php', 10));
        $round = ComparisonBuilder::round(ComparisonBuilder::finding('src/A.php', 11 + ReviewComparison::LINE_WINDOW));

        $result = $this->spots($loop, [$round]);

        self::assertSame([0, 1, 1], [count($result['matches']), count($result['loopOnly']), count($result['intaktOnly'])]);
    }

    public function testAFindingInAnotherFileIsNeverMatched(): void
    {
        $loop = ComparisonBuilder::loop(ComparisonBuilder::loopFinding('src/A.php', 10));
        $round = ComparisonBuilder::round(ComparisonBuilder::finding('src/B.php', 10));

        $result = $this->spots($loop, [$round]);

        self::assertCount(0, $result['matches']);
    }

    public function testTheSameSpotRaisedByTwoReviewersOnOneSideIsOneFinding(): void
    {
        $loop = ComparisonBuilder::loop(ComparisonBuilder::loopFinding('src/A.php', 10));
        $round = ComparisonBuilder::round(ComparisonBuilder::finding('src/A.php', 10));

        $result = $this->spots($loop, [$round, $round]);

        self::assertSame([1, 0, 0], [count($result['matches']), count($result['loopOnly']), count($result['intaktOnly'])]);
    }

    public function testTheSameLoopFindingRaisedByTwoLoopReviewersIsOneFinding(): void
    {
        $finding = ComparisonBuilder::loopFinding('src/A.php', 10);
        $loop = new LoopReviews([new LoopReview('a', LoopVerdict::Changes, [$finding]), new LoopReview('b', LoopVerdict::Changes, [$finding])]);
        $round = ComparisonBuilder::round(ComparisonBuilder::finding('src/A.php', 10));

        $result = $this->spots($loop, [$round]);

        self::assertSame([1, 0, 0], [count($result['matches']), count($result['loopOnly']), count($result['intaktOnly'])]);
    }

    public function testTheSameSpotAtTwoSeveritiesFromTwoIntaktReviewersIsOneFindingAtTheStricterSeverity(): void
    {
        $loop = ComparisonBuilder::loop(ComparisonBuilder::loopFinding('src/A.php', 10, Severity::P2));
        $p2 = ComparisonBuilder::round(ComparisonBuilder::finding('src/A.php', 10, Severity::P2));
        $p1 = ComparisonBuilder::round(ComparisonBuilder::finding('src/A.php', 10, Severity::P1));

        $result = $this->spots($loop, [$p2, $p1]);

        self::assertSame([1, 0, 0], [count($result['matches']), count($result['loopOnly']), count($result['intaktOnly'])]);
        self::assertSame(Severity::P1, $result['matches'][0][1]->severity);
    }

    public function testTwoIntaktReviewersWithinTheLineWindowOfEachOtherRaiseOneFinding(): void
    {
        $loop = ComparisonBuilder::loop(ComparisonBuilder::loopFinding('src/A.php', 10));
        $a = ComparisonBuilder::round(ComparisonBuilder::finding('src/A.php', 10));
        $b = ComparisonBuilder::round(ComparisonBuilder::finding('src/A.php', 11));

        $result = $this->spots($loop, [$a, $b]);

        self::assertSame([1, 0, 0], [count($result['matches']), count($result['loopOnly']), count($result['intaktOnly'])]);
    }

    public function testTheSameSpotAtTwoSeveritiesFromTwoLoopReviewersIsOneFindingAtTheStricterSeverity(): void
    {
        $loop = new LoopReviews([
            new LoopReview('a', LoopVerdict::Changes, [ComparisonBuilder::loopFinding('src/A.php', 10, Severity::P3)]),
            new LoopReview('b', LoopVerdict::Changes, [ComparisonBuilder::loopFinding('src/A.php', 10, Severity::P1)]),
        ]);
        $round = ComparisonBuilder::round(ComparisonBuilder::finding('src/A.php', 10, Severity::P1));

        $result = $this->spots($loop, [$round]);

        self::assertSame([1, 0, 0], [count($result['matches']), count($result['loopOnly']), count($result['intaktOnly'])]);
        self::assertSame(Severity::P1, $result['matches'][0][0]->severity);
    }

    public function testTwoLoopReviewersWithinTheLineWindowOfEachOtherRaiseOneFinding(): void
    {
        $loop = new LoopReviews([
            new LoopReview('a', LoopVerdict::Changes, [ComparisonBuilder::loopFinding('src/A.php', 10)]),
            new LoopReview('b', LoopVerdict::Changes, [ComparisonBuilder::loopFinding('src/A.php', 10 + ReviewComparison::LINE_WINDOW)]),
        ]);
        $round = ComparisonBuilder::round(ComparisonBuilder::finding('src/A.php', 10));

        $result = $this->spots($loop, [$round]);

        self::assertSame([1, 0, 0], [count($result['matches']), count($result['loopOnly']), count($result['intaktOnly'])]);
    }

    public function testTwoFindingsOnOneSideFurtherApartThanTheLineWindowStayTwo(): void
    {
        $loop = ComparisonBuilder::loop(ComparisonBuilder::loopFinding('src/A.php', 10));
        $round = ComparisonBuilder::round(
            ComparisonBuilder::finding('src/A.php', 10),
            ComparisonBuilder::finding('src/A.php', 11 + ReviewComparison::LINE_WINDOW),
        );

        $result = $this->spots($loop, [$round]);

        self::assertSame([1, 0, 1], [count($result['matches']), count($result['loopOnly']), count($result['intaktOnly'])]);
    }

    public function testAStricterFindingJoiningAMergedSpotKeepsTheEarlierLineForMatching(): void
    {
        $loop = ComparisonBuilder::loop(ComparisonBuilder::loopFinding('src/A.php', 7));
        $p2 = ComparisonBuilder::round(ComparisonBuilder::finding('src/A.php', 10, Severity::P2));
        $p1 = ComparisonBuilder::round(ComparisonBuilder::finding('src/A.php', 13, Severity::P1));

        $result = $this->spots($loop, [$p2, $p1]);

        self::assertSame([1, 0, 0], [count($result['matches']), count($result['loopOnly']), count($result['intaktOnly'])]);
        self::assertSame(Severity::P1, $result['matches'][0][1]->severity);
    }

    public function testAMergedSpotWhoseStricterFindingIsOutsideTheWindowStillMatchesByItsNearerLine(): void
    {
        $loop = ComparisonBuilder::loop(ComparisonBuilder::loopFinding('src/A.php', 5));
        $p3 = ComparisonBuilder::round(ComparisonBuilder::finding('src/A.php', 7, Severity::P3));
        $p1 = ComparisonBuilder::round(ComparisonBuilder::finding('src/A.php', 10, Severity::P1));

        $result = $this->spots($loop, [$p3, $p1]);

        self::assertSame([1, 0, 0], [count($result['matches']), count($result['loopOnly']), count($result['intaktOnly'])]);
    }

    public function testFindingsOfOneReviewNeverMergeSoEachMatchesItsOwnLoopFinding(): void
    {
        $loop = ComparisonBuilder::loop(ComparisonBuilder::loopFinding('src/A.php', 8), ComparisonBuilder::loopFinding('src/A.php', 15));
        $round = ComparisonBuilder::round(
            ComparisonBuilder::finding('src/A.php', 10),
            ComparisonBuilder::finding('src/A.php', 13),
        );

        $result = $this->spots($loop, [$round]);

        self::assertSame([2, 0, 0], [count($result['matches']), count($result['loopOnly']), count($result['intaktOnly'])]);
    }

    public function testNearbyFindingsOfOneReviewStaySeparateFindings(): void
    {
        $round = ComparisonBuilder::round(
            ComparisonBuilder::finding('src/A.php', 10),
            ComparisonBuilder::finding('src/A.php', 13),
            ComparisonBuilder::finding('src/A.php', 16),
            ComparisonBuilder::finding('src/A.php', 19),
        );

        $result = $this->spots(ComparisonBuilder::loop(), [$round]);

        self::assertCount(4, $result['intaktOnly']);
    }

    public function testFindingsOfSeveralReviewersMergeNoWiderThanTheLineWindow(): void
    {
        $rounds = array_map(
            static fn (int $line): array => ComparisonBuilder::round(ComparisonBuilder::finding('src/A.php', $line)),
            [10, 13, 16, 19],
        );

        $result = $this->spots(ComparisonBuilder::loop(), $rounds);

        self::assertCount(2, $result['intaktOnly']);
    }

    public function testAMergedLoopSpotMatchesByItsNearestLine(): void
    {
        $loop = new LoopReviews([
            new LoopReview('a', LoopVerdict::Changes, [ComparisonBuilder::loopFinding('src/A.php', 10)]),
            new LoopReview('b', LoopVerdict::Changes, [ComparisonBuilder::loopFinding('src/A.php', 13)]),
        ]);
        $round = ComparisonBuilder::round(ComparisonBuilder::finding('src/A.php', 16));

        $result = $this->spots($loop, [$round]);

        self::assertSame([1, 0, 0], [count($result['matches']), count($result['loopOnly']), count($result['intaktOnly'])]);
    }

    public function testFindingsOnOneSideMergeTheSameWhateverTheirOrder(): void
    {
        $a = ComparisonBuilder::round(ComparisonBuilder::finding('src/A.php', 10, Severity::P2));
        $b = ComparisonBuilder::round(ComparisonBuilder::finding('src/A.php', 16, Severity::P3));
        $c = ComparisonBuilder::round(ComparisonBuilder::finding('src/A.php', 12, Severity::P1));

        $first = $this->spots(ComparisonBuilder::loop(), [$a, $b, $c]);
        $second = $this->spots(ComparisonBuilder::loop(), [$c, $b, $a]);

        self::assertSame([Severity::P1, Severity::P3], array_map(static fn (LoopFinding $finding): Severity => $finding->severity, $first['intaktOnly']));
        self::assertSame([Severity::P1, Severity::P3], array_map(static fn (LoopFinding $finding): Severity => $finding->severity, $second['intaktOnly']));
    }

    public function testFindingsOfTwoReviewsOnTheSameLineMergeTheSameWhateverTheReviewersOrder(): void
    {
        $a = ComparisonBuilder::round(ComparisonBuilder::finding('src/A.php', 10), ComparisonBuilder::finding('src/A.php', 12));
        $b = ComparisonBuilder::round(ComparisonBuilder::finding('src/A.php', 12), ComparisonBuilder::finding('src/A.php', 14));

        $first = $this->spots(ComparisonBuilder::loop(), [$a, $b]);
        $second = $this->spots(ComparisonBuilder::loop(), [$b, $a]);

        self::assertSame([2, 2], [count($first['intaktOnly']), count($second['intaktOnly'])]);
    }

    public function testAnIntaktFindingMatchesOnlyOneLoopFinding(): void
    {
        $loop = ComparisonBuilder::loop(ComparisonBuilder::loopFinding('src/A.php', 10), ComparisonBuilder::loopFinding('src/A.php', 11 + ReviewComparison::LINE_WINDOW));
        $round = ComparisonBuilder::round(ComparisonBuilder::finding('src/A.php', 10 + ReviewComparison::LINE_WINDOW));

        $result = $this->spots($loop, [$round]);

        self::assertCount(1, $result['matches']);
        self::assertCount(1, $result['loopOnly']);
    }

    /**
     * @param list<list<LoopFinding>> $intaktReviews
     *
     * @return array{matches: list<array{LoopFinding, LoopFinding}>, loopOnly: list<LoopFinding>, intaktOnly: list<LoopFinding>}
     */
    private function spots(LoopReviews $loop, array $intaktReviews): array
    {
        $loopFindings = array_map(static fn (LoopReview $review): array => $review->findings, $loop->reviews);
        [$matches, $loopOnly, $intaktOnly] = new ReviewComparison()->matchedSpots($loopFindings, $intaktReviews);

        return ['matches' => $matches, 'loopOnly' => $loopOnly, 'intaktOnly' => $intaktOnly];
    }
}
