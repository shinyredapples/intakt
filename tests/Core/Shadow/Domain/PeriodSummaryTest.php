<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Shadow\Domain;

use Intakt\Core\Review\Contract\ChangeReview;
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
use Intakt\Core\Shadow\Contract\ShadowSummary;
use Intakt\Core\Shadow\Domain\PeriodSummary;
use Intakt\Core\Shadow\Domain\ReviewComparison;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PeriodSummary::class)]
#[CoversClass(ShadowSummary::class)]
#[UsesClass(ReviewComparison::class)]
#[UsesClass(HeadComparison::class)]
#[UsesClass(FindingMatch::class)]
#[UsesClass(ReviewerDecision::class)]
#[UsesClass(LoopReviews::class)]
#[UsesClass(LoopReview::class)]
#[UsesClass(LoopFinding::class)]
#[UsesClass(ChangeReview::class)]
#[UsesClass(PanelVerdict::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(Finding::class)]
#[UsesClass(RoundTokens::class)]
final class PeriodSummaryTest extends TestCase
{
    public function testThePeriodSummaryAddsUpTheHeadsMatchesDisagreementsFailuresAndTokens(): void
    {
        $comparison = new ReviewComparison();
        $first = $comparison->compare('a', ComparisonBuilder::loop(ComparisonBuilder::loopFinding('src/A.php', 1, Severity::P1)), [
            ComparisonBuilder::round(ComparisonBuilder::changes(ComparisonBuilder::finding('src/A.php', 1, Severity::P3), ComparisonBuilder::finding('src/Z.php', 9)), 100),
        ]);
        $second = $comparison->compare('b', new LoopReviews([new LoopReview('x', LoopVerdict::Unreadable, [])]), [
            ComparisonBuilder::round(Verdict::incompleteStream('cut'), 50),
        ]);

        $summary = new PeriodSummary()->summarize([$first, $second]);

        self::assertEquals(new ShadowSummary(2, 1, 0, 1, 1, 1, 1, 2, 150), $summary);
    }
}
