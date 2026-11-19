<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\ChangeUnderReview;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Plugin\Vendor\Contract\VendorReview;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

#[CoversClass(ScriptedJourneyVendor::class)]
#[UsesClass(JourneyVendor::class)]
final class ScriptedJourneyVendorTest extends TestCase
{
    public function testAReviewGoesToTheQuickVendorWhileNoScriptIsSet(): void
    {
        $shipped = $this->createMock(Vendor::class);
        $shipped->expects(self::never())->method('review');

        $review = new ScriptedJourneyVendor(new JourneyVendor(), $shipped, null)->review('sample', Tier::Standard, $this->request());

        self::assertEquals(new Verdict(Decision::Approve, [], [], []), $review->verdict);
    }

    public function testAReviewGoesToTheShippedVendorOnceAScriptIsSet(): void
    {
        $answer = new VendorReview(new Verdict(Decision::Blocked, [], [], []));
        $shipped = self::createStub(Vendor::class);
        $shipped->method('review')->willReturn($answer);

        $review = new ScriptedJourneyVendor(new JourneyVendor(), $shipped, '/script')->review('sample', Tier::Standard, $this->request());

        self::assertSame($answer, $review);
    }

    public function testTheQuickVendorAnswersTheMappingWhetherOrNotAScriptIsSet(): void
    {
        $shipped = self::createStub(Vendor::class);
        $shipped->method('mapsAModelTo')->willReturn(false);

        self::assertTrue(new ScriptedJourneyVendor(new JourneyVendor(), $shipped, '/script')->mapsAModelTo('sample', Tier::Standard));
    }

    private function request(): ReviewRequest
    {
        return new ReviewRequest(new Checkout(__DIR__), new ReviewBrief(new ChangeUnderReview('sample', 'task', 'diff'), [], 1), new ReviewerConversation('role', new UuidV7()));
    }
}
