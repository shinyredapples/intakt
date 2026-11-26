<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Domain;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\ReviewersAgree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewersAgree::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(Finding::class)]
final class ReviewersAgreeTest extends TestCase
{
    public function testReviewersWhoAllApproveWithNoFindingLeftAgree(): void
    {
        self::assertTrue(new ReviewersAgree()->over([$this->approval(), $this->approval()], []));
    }

    public function testAP3LeftAfterTheQuoteCheckNeedsAMarkSoTheReviewersDoNotAgree(): void
    {
        $nit = $this->at('A', Severity::P3, 3);

        self::assertFalse(new ReviewersAgree()->over([$this->changes($nit), $this->changes($nit)], [$nit]));
    }

    public function testOneReviewerApprovingAndOneRequestingChangesDoNotAgree(): void
    {
        $bug = $this->at('A', Severity::P1, 3);

        self::assertFalse(new ReviewersAgree()->over([$this->approval(), $this->changes($bug)], [$bug]));
    }

    public function testABlockingFindingEveryReviewerReportsAtItsFileAndLineIsShared(): void
    {
        $bug = $this->at('A', Severity::P1, 3);
        $same = $this->at('B', Severity::P2, 3);

        self::assertTrue(new ReviewersAgree()->over([$this->changes($bug), $this->changes($same)], [$bug, $same]));
    }

    public function testABlockingFindingOnlyOneReviewerReportsIsNotShared(): void
    {
        $bug = $this->at('A', Severity::P1, 3);
        $elsewhere = $this->at('B', Severity::P1, 4);

        self::assertFalse(new ReviewersAgree()->over([$this->changes($bug), $this->changes($elsewhere)], [$bug, $elsewhere]));
    }

    public function testAP3AnotherReviewerReportsAtTheSamePlaceDoesNotShareABlockingFinding(): void
    {
        $bug = $this->at('A', Severity::P1, 3);

        self::assertFalse(new ReviewersAgree()->over([$this->changes($bug), $this->changes($this->at('B', Severity::P3, 3))], [$bug]));
    }

    public function testABlockingFindingWithoutALineIsNeverShared(): void
    {
        $bug = new Finding('A', Severity::P1, 'src/A.php', null, null, 'Broken.', FixableBy::Builder, false, null, null);

        self::assertFalse(new ReviewersAgree()->over([$this->changes($bug), $this->changes($bug)], [$bug]));
    }

    public function testABlockingFindingWithoutAFileIsNeverShared(): void
    {
        $bug = new Finding('A', Severity::P1, null, 3, null, 'Broken.', FixableBy::Builder, false, null, null);

        self::assertFalse(new ReviewersAgree()->over([$this->changes($bug), $this->changes($bug)], [$bug]));
    }

    public function testALoneReviewersBlockingFindingIsNotShared(): void
    {
        $bug = $this->at('A', Severity::P1, 3);

        self::assertFalse(new ReviewersAgree()->over([$this->changes($bug)], [$bug]));
    }

    public function testALoneReviewerWhoApprovesWithNoFindingLeftAgrees(): void
    {
        self::assertTrue(new ReviewersAgree()->over([$this->approval()], []));
    }

    public function testThreeReviewersShareAFindingOnlyWhenEachReportsItsPlace(): void
    {
        $bug = $this->at('A', Severity::P1, 3);

        self::assertFalse(new ReviewersAgree()->over([$this->changes($bug), $this->changes($bug), $this->approval()], [$bug]));
    }

    public function testAReviewerWhoseReviewFailedNeverAgrees(): void
    {
        self::assertFalse(new ReviewersAgree()->over([$this->approval(), Verdict::noTools()], []));
    }

    public function testAReviewerClaimingAFailedReviewsIdInAChangesVerdictNeverAgrees(): void
    {
        $claimed = new Finding(Verdict::NO_TOOLS, Severity::P1, 'src/A.php', 3, null, 'Broken.', FixableBy::Builder, false, null, null);
        $bug = $this->at('A', Severity::P1, 3);

        self::assertFalse(new ReviewersAgree()->over([$this->changes($bug), $this->changes($claimed)], [$bug]));
    }

    public function testAReviewerWithoutAStoredVerdictNeverAgrees(): void
    {
        self::assertFalse(new ReviewersAgree()->over([$this->approval(), null], []));
    }

    public function testARoundWithoutAnyVerdictIsNoAgreement(): void
    {
        self::assertFalse(new ReviewersAgree()->over([], []));
    }

    private function approval(): Verdict
    {
        return new Verdict(Decision::Approve, [], [], []);
    }

    private function at(string $id, Severity $severity, int $line): Finding
    {
        return new Finding($id, $severity, 'src/A.php', $line, null, 'Text of '.$id.'.', FixableBy::Builder, false, null, null);
    }

    private function changes(Finding $finding): Verdict
    {
        return new Verdict(Decision::Changes, [$finding], [], []);
    }
}
