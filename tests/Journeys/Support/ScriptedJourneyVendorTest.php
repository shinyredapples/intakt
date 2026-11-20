<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Review\Contract\ChangeUnderReview;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

#[CoversClass(ScriptedJourneyVendor::class)]
#[UsesClass(JourneyVendor::class)]
#[UsesClass(ChangeUnderReview::class)]
#[UsesClass(Checkout::class)]
#[UsesClass(Judgement::class)]
#[UsesClass(JudgeRequest::class)]
#[UsesClass(Paths::class)]
#[UsesClass(ReviewBrief::class)]
#[UsesClass(ReviewerConversation::class)]
#[UsesClass(ReviewRequest::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(VendorReview::class)]
final class ScriptedJourneyVendorTest extends TestCase
{
    public function testWithoutAScriptDirectoryAReviewIsAnsweredAsTheJourneyVendorAnswersIt(): void
    {
        $vendor = new ScriptedJourneyVendor(static fn (): Vendor => throw new LogicException('The wrapped vendor was asked.'), new JourneyVendor(), '');

        self::assertEquals(new VendorReview(new Verdict(Decision::Approve, [], [], [])), $vendor->review('sample', Tier::Standard, $this->request()));
    }

    public function testWithAScriptDirectoryAReviewIsAnsweredByTheWrappedVendor(): void
    {
        $review = new VendorReview(new Verdict(Decision::Changes, [], [], []));
        $wrapped = self::createStub(Vendor::class);
        $wrapped->method('review')->willReturn($review);

        $vendor = new ScriptedJourneyVendor(static fn (): Vendor => $wrapped, new JourneyVendor(), '/scripts');

        self::assertSame($review, $vendor->review('sample', Tier::Standard, $this->request()));
    }

    public function testWithAScriptDirectoryAJudgementIsAnsweredByTheWrappedVendor(): void
    {
        $judgement = new Judgement([]);
        $wrapped = self::createStub(Vendor::class);
        $wrapped->method('judge')->willReturn($judgement);

        $vendor = new ScriptedJourneyVendor(static fn (): Vendor => $wrapped, new JourneyVendor(), '/scripts');

        self::assertSame($judgement, $vendor->judge('sample', new JudgeRequest('Add a cart.', [], new UuidV7(), new Paths())));
    }

    public function testWithAScriptDirectoryTheWrappedVendorTellsWhetherATierIsMapped(): void
    {
        $wrapped = self::createStub(Vendor::class);
        $wrapped->method('mapsAModelTo')->willReturn(false);

        $vendor = new ScriptedJourneyVendor(static fn (): Vendor => $wrapped, new JourneyVendor(), '/scripts');

        self::assertFalse($vendor->mapsAModelTo('sample', Tier::Standard));
    }

    private function request(): ReviewRequest
    {
        return new ReviewRequest(new Checkout(__DIR__), new ReviewBrief(new ChangeUnderReview('sample', 'Add a cart.', 'diff --git a/a b/a'), [], 1), new ReviewerConversation('You review for correctness.', new UuidV7()));
    }
}
