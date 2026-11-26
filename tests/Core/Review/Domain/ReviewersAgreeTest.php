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
    public function testABlockingFindingEveryReviewerReportsAtItsPlaceInTheSameWordsIsShared(): void
    {
        $bug = $this->at('A', Severity::P1, 3);
        $same = $bug->withId('B');

        self::assertSame([$bug, $same], new ReviewersAgree()->sharedIn([$this->changes($bug), $this->changes($same)], [$bug, $same]));
    }

    public function testTheSameWordsWithOtherSpacingAreStillShared(): void
    {
        $bug = $this->at('A', Severity::P1, 3);
        $spaced = new Finding('B', Severity::P1, 'src/A.php', 3, null, '  '.str_replace(' ', "\n ", $bug->text).' ', FixableBy::Builder, false, null, null);

        self::assertNotSame($bug->text, $spaced->text);
        self::assertSame([$bug], new ReviewersAgree()->sharedIn([$this->changes($bug), $this->changes($spaced)], [$bug]));
    }

    public function testFindingsWhoseWordsDifferOnlyInCaseAreNotShared(): void
    {
        $lower = new Finding('A', Severity::P1, 'src/Totals.php', 12, null, 'The calculation ignores `$total`.', FixableBy::Builder, false, null, null);
        $upper = new Finding('B', Severity::P1, 'src/Totals.php', 12, null, 'The calculation ignores `$Total`.', FixableBy::Builder, false, null, null);

        self::assertSame([], new ReviewersAgree()->sharedIn([$this->changes($lower), $this->changes($upper)], [$lower, $upper]));
    }

    public function testFindingsThatDifferOnlyInTheWhiteSpaceInsideACodeSpanAreNotShared(): void
    {
        $single = new Finding('A', Severity::P1, 'src/Labels.php', 12, null, 'The check rejects `"A B"`.', FixableBy::Builder, false, null, null);
        $double = new Finding('B', Severity::P1, 'src/Labels.php', 12, null, 'The check rejects `"A  B"`.', FixableBy::Builder, false, null, null);

        self::assertSame([], new ReviewersAgree()->sharedIn([$this->changes($single), $this->changes($double)], [$single, $double]));
    }

    public function testFindingsThatDifferOnlyInTheWhiteSpaceOfTheProseAroundACodeSpanAreStillShared(): void
    {
        $tight = new Finding('A', Severity::P1, 'src/Labels.php', 12, null, 'The check rejects `"A  B"` here.', FixableBy::Builder, false, null, null);
        $loose = new Finding('B', Severity::P1, 'src/Labels.php', 12, null, " The check\n rejects  `\"A  B\"`\t here. ", FixableBy::Builder, false, null, null);

        self::assertSame([$tight], new ReviewersAgree()->sharedIn([$this->changes($tight), $this->changes($loose)], [$tight]));
    }

    public function testFindingsWhoseTextIsNotValidUtf8AreComparedExactly(): void
    {
        $first = new Finding('A', Severity::P1, 'src/Labels.php', 12, null, "The check rejects \xFF here.", FixableBy::Builder, false, null, null);
        $second = new Finding('B', Severity::P1, 'src/Labels.php', 12, null, "The check  rejects \xFE here.", FixableBy::Builder, false, null, null);

        self::assertSame([], new ReviewersAgree()->sharedIn([$this->changes($first), $this->changes($second)], [$first, $second]));
    }

    public function testAFindingWithSeveralCodeSpansIsSharedOnlyWhenEverySpanMatchesExactly(): void
    {
        $first = new Finding('A', Severity::P1, 'src/Labels.php', 12, null, 'Use `a b` instead of `c d`.', FixableBy::Builder, false, null, null);
        $second = new Finding('B', Severity::P1, 'src/Labels.php', 12, null, 'Use `a b` instead of `c  d`.', FixableBy::Builder, false, null, null);

        self::assertSame([], new ReviewersAgree()->sharedIn([$this->changes($first), $this->changes($second)], [$first, $second]));
    }

    public function testTwoUnrelatedDefectsReportedAtOneLineAreNotShared(): void
    {
        $authorization = new Finding('A', Severity::P1, 'src/Endpoint.php', 12, null, 'The endpoint is missing an access check.', FixableBy::Builder, false, null, null);
        $serialization = new Finding('B', Severity::P1, 'src/Endpoint.php', 12, null, 'The response omits the requested total.', FixableBy::Builder, false, null, null);

        self::assertSame([], new ReviewersAgree()->sharedIn([$this->changes($authorization), $this->changes($serialization)], [$authorization, $serialization]));
    }

    public function testTheSameWordsAtOneLineWithAnotherSeverityAreNotShared(): void
    {
        $bug = $this->at('A', Severity::P1, 3);

        self::assertSame([], new ReviewersAgree()->sharedIn([$this->changes($bug), $this->changes($this->at('A', Severity::P2, 3))], [$bug]));
    }

    public function testABlockingFindingOnlyOneReviewerReportsIsNotShared(): void
    {
        $bug = $this->at('A', Severity::P1, 3);
        $elsewhere = $this->at('A', Severity::P1, 4);

        self::assertSame([], new ReviewersAgree()->sharedIn([$this->changes($bug), $this->changes($elsewhere)], [$bug, $elsewhere]));
    }

    public function testOnlyTheSharedFindingIsReturnedBesideOneThatIsNot(): void
    {
        $bug = $this->at('A', Severity::P1, 3);
        $lone = $this->at('C', Severity::P2, 9);

        self::assertSame([$bug], new ReviewersAgree()->sharedIn([new Verdict(Decision::Changes, [$bug, $lone], [], []), $this->changes($bug->withId('B'))], [$bug, $lone]));
    }

    public function testAP3EveryReviewerReportsIsNeverShared(): void
    {
        $nit = $this->at('A', Severity::P3, 3);

        self::assertSame([], new ReviewersAgree()->sharedIn([$this->changes($nit), $this->changes($nit)], [$nit]));
    }

    public function testAReviewerWhoApprovesSharesNoFindingAlsoWhenItListsTheSameOne(): void
    {
        $bug = $this->at('A', Severity::P1, 3);

        self::assertSame([], new ReviewersAgree()->sharedIn([new Verdict(Decision::Approve, [$bug], [], []), $this->changes($bug)], [$bug]));
    }

    public function testOneReviewerApprovingAndOneRequestingChangesShareNothing(): void
    {
        $bug = $this->at('A', Severity::P1, 3);

        self::assertSame([], new ReviewersAgree()->sharedIn([new Verdict(Decision::Approve, [], [], []), $this->changes($bug)], [$bug]));
    }

    public function testABlockingFindingWithoutALineIsNeverShared(): void
    {
        $bug = new Finding('A', Severity::P1, 'src/A.php', null, null, 'Broken.', FixableBy::Builder, false, null, null);

        self::assertSame([], new ReviewersAgree()->sharedIn([$this->changes($bug), $this->changes($bug)], [$bug]));
    }

    public function testABlockingFindingWithoutAFileIsNeverShared(): void
    {
        $bug = new Finding('A', Severity::P1, null, 3, null, 'Broken.', FixableBy::Builder, false, null, null);

        self::assertSame([], new ReviewersAgree()->sharedIn([$this->changes($bug), $this->changes($bug)], [$bug]));
    }

    public function testALoneReviewersBlockingFindingIsNotShared(): void
    {
        $bug = $this->at('A', Severity::P1, 3);

        self::assertSame([], new ReviewersAgree()->sharedIn([$this->changes($bug)], [$bug]));
    }

    public function testThreeReviewersShareAFindingOnlyWhenEachReportsIt(): void
    {
        $bug = $this->at('A', Severity::P1, 3);

        self::assertSame([], new ReviewersAgree()->sharedIn([$this->changes($bug), $this->changes($bug), $this->changes($this->at('C', Severity::P1, 8))], [$bug]));
    }

    public function testAReviewerWhoseReviewFailedSharesNothing(): void
    {
        $bug = $this->at('A', Severity::P1, 3);

        self::assertSame([], new ReviewersAgree()->sharedIn([$this->changes($bug), Verdict::noTools()], [$bug]));
    }

    public function testAReviewerClaimingAFailedReviewsIdInAChangesVerdictSharesNothing(): void
    {
        $claimed = new Finding(Verdict::NO_TOOLS, Severity::P1, 'src/A.php', 3, null, 'Text of A.', FixableBy::Builder, false, null, null);
        $bug = $this->at('A', Severity::P1, 3);

        self::assertSame([], new ReviewersAgree()->sharedIn([$this->changes($bug), $this->changes($claimed)], [$bug]));
    }

    public function testAReviewerWithoutAStoredVerdictSharesNothing(): void
    {
        $bug = $this->at('A', Severity::P1, 3);

        self::assertSame([], new ReviewersAgree()->sharedIn([$this->changes($bug), null], [$bug]));
    }

    public function testABlockedReviewerSharesNothing(): void
    {
        $bug = $this->at('A', Severity::P1, 3);

        self::assertSame([], new ReviewersAgree()->sharedIn([$this->changes($bug), new Verdict(Decision::Blocked, [$bug], [], [])], [$bug]));
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
