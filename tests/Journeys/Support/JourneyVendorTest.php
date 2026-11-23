<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Review\Contract\ChangeUnderReview;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\JudgedFinding;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\Mark;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

#[CoversClass(JourneyVendor::class)]
#[UsesClass(ChangeUnderReview::class)]
#[UsesClass(Checkout::class)]
#[UsesClass(Finding::class)]
#[UsesClass(JudgedFinding::class)]
#[UsesClass(Judgement::class)]
#[UsesClass(JudgeRequest::class)]
#[UsesClass(Paths::class)]
#[UsesClass(ReviewBrief::class)]
#[UsesClass(ReviewerConversation::class)]
#[UsesClass(ReviewRequest::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(VendorReview::class)]
final class JourneyVendorTest extends TestCase
{
    public function testEveryReviewApprovesWithoutFindings(): void
    {
        $request = new ReviewRequest(new Checkout(__DIR__), new ReviewBrief(new ChangeUnderReview('sample', 'Add a cart.', 'diff --git a/a b/a'), [], 1), new ReviewerConversation('You review for correctness.', new UuidV7()));

        $review = new JourneyVendor()->review('sample', Tier::Standard, $request);

        self::assertEquals(new VendorReview(new Verdict(Decision::Approve, [], [], [])), $review);
    }

    public function testAJudgementActsOnTheBlockingFindingsOnly(): void
    {
        $findings = [$this->finding('A', Severity::P1), $this->finding('B', Severity::P3)];

        $judgement = new JourneyVendor()->judge('sample', new JudgeRequest('Add a cart.', $findings, new UuidV7(), new Paths()));

        self::assertEquals([new JudgedFinding('A', Mark::Act)], $judgement->marks);
    }

    private function finding(string $id, Severity $severity): Finding
    {
        return new Finding($id, $severity, null, null, null, 'Text of '.$id.'.', FixableBy::Builder, false, null, null);
    }
}
