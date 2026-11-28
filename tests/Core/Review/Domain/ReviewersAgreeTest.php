<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Domain;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FindingKind;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\FollowUpNote;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\QuotesInChange;
use Intakt\Core\Review\Domain\ReviewersAgree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewersAgree::class)]
#[UsesClass(QuotesInChange::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(Finding::class)]
final class ReviewersAgreeTest extends TestCase
{
    private const string CHANGE = "+    public function total(): int\n+    {\n+        return \$this->sum;\n";
    private const string LITERALS = "+    return total(\"A B\") . total(\"A  B\");\n";

    public function testReviewersWhoAllApproveAgree(): void
    {
        self::assertTrue($this->agree([$this->approval(), $this->approval()]));
    }

    public function testReviewersOfARoundWithoutVerdictsDoNotAgree(): void
    {
        self::assertFalse($this->agree([]));
    }

    public function testAnApprovalAndAChangesVerdictDoNotAgree(): void
    {
        self::assertFalse($this->agree([$this->approval(), $this->changes($this->finding('The `total` loses the discount.'))]));
    }

    public function testReviewersWhoBlockWithTheSameFindingAgree(): void
    {
        $text = 'The `total` loses the discount.';

        self::assertTrue($this->agree([$this->changes($this->finding($text)), $this->changes($this->finding($text))]));
    }

    public function testReviewersWhoShareEveryP2FindingAgree(): void
    {
        $first = 'The `total` loses the discount.';
        $second = 'The `sum` is never reset.';

        self::assertTrue($this->agree([
            $this->changes($this->finding($first, Severity::P2), $this->finding($second, Severity::P2)),
            $this->changes($this->finding($second, Severity::P2), $this->finding($first, Severity::P2)),
        ]));
    }

    public function testReviewersWithABlockingFindingNotAllOfThemRaiseDoNotAgree(): void
    {
        $shared = 'The `total` loses the discount.';

        self::assertFalse($this->agree([
            $this->changes($this->finding($shared), $this->finding('The `sum` is never reset.')),
            $this->changes($this->finding($shared)),
        ]));
    }

    public function testReviewersWhoRaiseTheSameTextAtDifferentSeveritiesDoNotAgree(): void
    {
        $text = 'The `total` loses the discount.';

        self::assertFalse($this->agree([$this->changes($this->finding($text)), $this->changes($this->finding($text, Severity::P2))]));
    }

    public function testAP3FindingOfAnyReviewerIsNotAgreement(): void
    {
        $text = 'The `total` loses the discount.';

        self::assertFalse($this->agree([
            $this->changes($this->finding($text), $this->finding('The `sum` could read better.', Severity::P3)),
            $this->changes($this->finding($text)),
        ]));
    }

    public function testASharedP3FindingIsNotAgreement(): void
    {
        $text = 'The `sum` could read better.';

        self::assertFalse($this->agree([$this->changes($this->finding($text, Severity::P3)), $this->changes($this->finding($text, Severity::P3))]));
    }

    public function testAnApprovalCarryingAP3FindingIsNotAgreement(): void
    {
        $approval = new Verdict(Decision::Approve, [$this->finding('The `sum` could read better.', Severity::P3)], [], []);

        self::assertFalse($this->agree([$approval, $this->approval()]));
    }

    public function testAnApprovalWithAFollowUpIsNotAgreement(): void
    {
        $approval = new Verdict(Decision::Approve, [], [], [new FollowUpNote('Rename `sum` in a later change.')]);

        self::assertFalse($this->agree([$approval, $this->approval()]));
    }

    public function testAFailedReviewIsNotAgreement(): void
    {
        self::assertFalse($this->agree([Verdict::noTools(), Verdict::noTools()]));
    }

    public function testASharedFindingWhoseQuotesAreNotInTheChangeIsNotAgreement(): void
    {
        $text = 'The `discountRate` is applied twice.';

        self::assertFalse($this->agree([$this->changes($this->finding($text)), $this->changes($this->finding($text))]));
    }

    public function testASharedFindingWithoutAQuoteToCheckPassesTheQuoteCheck(): void
    {
        $text = 'The cart loses its discount.';

        self::assertTrue($this->agree([$this->changes($this->finding($text)), $this->changes($this->finding($text))]));
    }

    public function testFindingsThatDifferOnlyInLetterCaseAreNotShared(): void
    {
        self::assertFalse($this->agree([
            $this->changes($this->finding('The `total` loses the discount.')),
            $this->changes($this->finding('the `total` loses the discount.')),
        ]));
    }

    public function testFindingsThatDifferOnlyInLetterCaseInsideACodeSpanAreNotShared(): void
    {
        self::assertFalse($this->agree([
            $this->changes($this->finding('The `total` loses the discount.')),
            $this->changes($this->finding('The `Total` loses the discount.')),
        ]));
    }

    public function testFindingsThatDifferOnlyInWhiteSpaceInsideACodeSpanAreNotShared(): void
    {
        self::assertFalse($this->agree([
            $this->changes($this->finding('The literal `"A B"` in `total` is wrong.')),
            $this->changes($this->finding('The literal `"A  B"` in `total` is wrong.')),
        ]));
    }

    public function testFindingsThatDifferOnlyInWhiteSpaceOfTheProseAroundACodeSpanAreShared(): void
    {
        self::assertTrue($this->agree([
            $this->changes($this->finding('The literal `"A B"` in `total` is wrong.')),
            $this->changes($this->finding("  The   literal\n`\"A B\"`  in\t`total` is wrong. ")),
        ]));
    }

    public function testTheSameFindingAtDifferentFilesIsNotShared(): void
    {
        $text = 'The cart loses its discount.';

        self::assertFalse($this->agree([
            $this->changes($this->finding($text)),
            $this->changes($this->finding($text, file: 'src/Order.php')),
        ]));
    }

    public function testFindingsThatDifferOnlyInWhiteSpaceInsideACodeSpanAfterAnEscapedBacktickAreNotShared(): void
    {
        self::assertFalse($this->agree([
            $this->changes($this->finding('A \` marker and `"A B"` in `total` are wrong.')),
            $this->changes($this->finding('A \` marker and `"A  B"` in `total` are wrong.')),
        ], self::LITERALS));
    }

    public function testFindingsThatDifferOnlyInProseWhiteSpaceAfterAnEscapedBacktickAreShared(): void
    {
        self::assertTrue($this->agree([
            $this->changes($this->finding('A \` marker and `"A B"` in `total` are wrong.')),
            $this->changes($this->finding('A \`  marker  and `"A B"` in `total` are wrong.')),
        ], self::LITERALS));
    }

    public function testFindingsThatDifferOnlyInANonbreakingSpaceOfTheProseAreShared(): void
    {
        self::assertTrue($this->agree([
            $this->changes($this->finding("The\u{00A0}cart loses its discount.")),
            $this->changes($this->finding('The cart loses its discount.')),
        ]));
    }

    public function testFindingsThatAreNotValidUtf8AndDifferAreNotShared(): void
    {
        self::assertFalse($this->agree([
            $this->changes($this->finding("The cart\xFF loses its discount.")),
            $this->changes($this->finding("The cart\xFE loses its discount.")),
        ]));
    }

    public function testReviewersWhoAskForChangesWithoutAFindingDoNotAgree(): void
    {
        self::assertFalse($this->agree([$this->changes(), $this->changes()]));
    }

    public function testAnApprovalAndAChangesVerdictWithTheSameFindingDoNotAgree(): void
    {
        $finding = $this->finding('The cart loses its discount.');

        self::assertFalse($this->agree([new Verdict(Decision::Approve, [$finding], [], []), $this->changes($finding)]));
    }

    public function testTheOnlyVerdictOfARoundAgreesWithItself(): void
    {
        self::assertTrue($this->agree([$this->changes($this->finding('The cart loses its discount.'))]));
    }

    private function approval(): Verdict
    {
        return new Verdict(Decision::Approve, [], [], []);
    }

    /**
     * @param list<Verdict> $verdicts
     */
    private function agree(array $verdicts, string $change = self::CHANGE): bool
    {
        return new ReviewersAgree()->agree($verdicts, $change);
    }

    private function finding(string $text, Severity $severity = Severity::P1, string $file = 'src/Cart.php'): Finding
    {
        return new Finding(
            id: 'a1',
            severity: $severity,
            file: $file,
            line: 12,
            symbol: null,
            text: $text,
            fixable_by: FixableBy::Builder,
            repeat: false,
            requirement: null,
            evidence: null,
            title: 'The cart loses its discount',
            kind: FindingKind::Other,
        );
    }

    private function changes(Finding ...$findings): Verdict
    {
        return new Verdict(Decision::Changes, array_values($findings), [], []);
    }
}
