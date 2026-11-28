<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\Domain;

use Intakt\Core\Build\Domain\HtmlBlock;
use Intakt\Core\Build\Domain\ReadableLines;
use Intakt\Core\Build\Domain\RegressionEvidence;
use Intakt\Core\Build\Domain\TextPlace;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\HandBack\Contract\RegressionRun;
use Intakt\Core\HandBack\Contract\RegressionRuns;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegressionEvidence::class)]
#[CoversClass(ReadableLines::class)]
#[CoversClass(HtmlBlock::class)]
#[UsesClass(RegressionRuns::class)]
#[UsesClass(RegressionRun::class)]
#[UsesClass(Paths::class)]
#[UsesClass(TextPlace::class)]
final class RegressionEvidenceTest extends TestCase
{
    private const string LINES = "- Regression tests: `tests/FooTest.php`\n- On the base: failed\n- On the head: passed";

    public function testABodyWithAnEvidenceHeadingGetsTheResultsUnderIt(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("Summary.\n\n## Evidence\n\n- E1: shown\n\n## Follow-ups\n\n- none");

        self::assertSame("Summary.\n\n## Evidence\n\n- E1: shown\n\n".self::LINES."\n\n## Follow-ups\n\n- none", $body);
    }

    public function testABodyWithoutAnEvidenceHeadingGetsOneAtTheEnd(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo('Summary.');

        self::assertSame("Summary.\n\n## Evidence\n\n".self::LINES, $body);
    }

    public function testAnEmptyBodyGetsOnlyTheEvidenceSection(): void
    {
        self::assertSame("## Evidence\n\n".self::LINES, new RegressionEvidence($this->runs())->addedTo(''));
    }

    public function testAnEvidenceHeadingInsideACodeFenceIsNotTheEvidenceSection(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("```\n## Evidence\n```\n\nText.");

        self::assertSame("```\n## Evidence\n```\n\nText.\n\n## Evidence\n\n".self::LINES, $body);
    }

    public function testATildeLineInsideABacktickFenceDoesNotCloseIt(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("```\n~~~\n## Evidence\n```");

        self::assertSame("```\n~~~\n## Evidence\n```\n\n## Evidence\n\n".self::LINES, $body);
    }

    public function testAThreeBacktickLineInsideAFourBacktickFenceDoesNotCloseIt(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("````\n```\n## Evidence\n````");

        self::assertSame("````\n```\n## Evidence\n````\n\n## Evidence\n\n".self::LINES, $body);
    }

    public function testALongerClosingLineClosesAFence(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("```\ncode\n`````\n\n## Evidence");

        self::assertSame("```\ncode\n`````\n\n## Evidence\n\n".self::LINES, $body);
    }

    public function testABodyThatEndsInAnUnclosedFenceIsClosedBeforeANewEvidenceHeading(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("Text.\n\n~~~~\ncode");

        self::assertSame("Text.\n\n~~~~\ncode\n~~~~\n\n## Evidence\n\n".self::LINES, $body);
    }

    public function testABodyThatEndsInAnUnclosedFenceInsideEvidenceIsClosedBeforeTheResults(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("## Evidence\n\n```\noutput");

        self::assertSame("## Evidence\n\n```\noutput\n```\n\n".self::LINES, $body);
    }

    public function testAnEvidenceHeadingInsideAKindSixBlockIsNotTheEvidenceSection(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("<div>\n## Evidence\n\n- E1: shown");

        self::assertSame("<div>\n## Evidence\n\n- E1: shown\n\n## Evidence\n\n".self::LINES, $body);
    }

    public function testAnEvidenceHeadingAfterTheBlankLineThatEndsAKindSixBlockIsTheEvidenceSection(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("<div>\ntext\n\n## Evidence\n\n- E1: shown");

        self::assertSame("<div>\ntext\n\n## Evidence\n\n- E1: shown\n\n".self::LINES, $body);
    }

    public function testASpanLineOnItsOwnStartsABlockThatHidesAnEvidenceHeading(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("<span>\n## Evidence\n\n- E1: shown");

        self::assertSame("<span>\n## Evidence\n\n- E1: shown\n\n## Evidence\n\n".self::LINES, $body);
    }

    public function testAClosingSpanLineOnItsOwnStartsABlockThatHidesAnEvidenceHeading(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("</span>\n## Evidence\n\n- E1: shown");

        self::assertSame("</span>\n## Evidence\n\n- E1: shown\n\n## Evidence\n\n".self::LINES, $body);
    }

    public function testASpanLineDirectlyBelowParagraphTextStartsNoBlock(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("Evidence\n<span>\n---\n\n- E1: shown");

        self::assertSame("Evidence\n<span>\n---\n\n- E1: shown\n\n".self::LINES, $body);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function endTagsOfTheRawBlocks(): iterable
    {
        foreach (['pre', 'script', 'style', 'textarea'] as $opening) {
            foreach (['pre', 'script', 'style', 'textarea'] as $end) {
                yield $opening.' ended by '.$end => [$opening, $end];
            }
        }
    }

    #[DataProvider('endTagsOfTheRawBlocks')]
    public function testAnEvidenceHeadingInsideAKindOneBlockIsHiddenUntilAnyOfTheFourEndTags(string $opening, string $end): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo('<'.$opening.">\n## Evidence\n</".$end.">\n## Evidence\n\n- E1: shown");

        $closing = $opening === $end ? '' : "\n</".$opening.'>';
        self::assertSame('<'.$opening.">\n## Evidence\n</".$end.">\n## Evidence\n\n- E1: shown".$closing."\n\n".self::LINES, $body);
    }

    public function testATextareaBlockEndedByAPreEndTagGetsItsOwnEndTagBeforeTheResults(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("<textarea>\nExample.\n</pre>");

        self::assertSame("<textarea>\nExample.\n</pre>\n</textarea>\n\n## Evidence\n\n".self::LINES, $body);
    }

    public function testATextareaClosedByItsOwnEndTagAfterTheBlockEndedGetsNoSecondEndTag(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("<textarea>\n</pre>\n</textarea>");

        self::assertSame("<textarea>\n</pre>\n</textarea>\n\n## Evidence\n\n".self::LINES, $body);
    }

    public function testAnOpenFenceIsClosedBeforeAnOpenElementsEndTag(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("<textarea>\n</pre>\n```\ncode");

        self::assertSame("<textarea>\n</pre>\n```\ncode\n```\n</textarea>\n\n## Evidence\n\n".self::LINES, $body);
    }

    public function testAnElementLeftOpenInsideTheEvidenceSectionIsClosedBeforeTheResults(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("## Evidence\n\n<textarea>\nx\n</pre>\n\n## Follow-ups\n\n- none");

        self::assertSame("## Evidence\n\n<textarea>\nx\n</pre>\n</textarea>\n\n".self::LINES."\n\n## Follow-ups\n\n- none", $body);
    }

    public function testAnElementClosedOnlyAfterTheEvidenceSectionIsClosedBeforeTheResults(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("## Evidence\n\n<textarea>\n</pre>\n\n## Follow-ups\n\n</textarea>");

        self::assertSame("## Evidence\n\n<textarea>\n</pre>\n</textarea>\n\n".self::LINES."\n\n## Follow-ups\n\n</textarea>", $body);
    }

    public function testAnEndTagInsideAFenceLeavesTheElementOpen(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("<textarea>\n</pre>\n```\n</textarea>\n```");

        self::assertSame("<textarea>\n</pre>\n```\n</textarea>\n```\n</textarea>\n\n## Evidence\n\n".self::LINES, $body);
    }

    public function testAnEndTagInIndentedCodeLeavesTheElementOpen(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("<textarea>\n</pre>\n\n    </textarea>");

        self::assertSame("<textarea>\n</pre>\n\n    </textarea>\n</textarea>\n\n## Evidence\n\n".self::LINES, $body);
    }

    public function testAnEndTagInAParagraphClosesTheElement(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("<textarea>\n</pre>\n\nText </textarea>");

        self::assertSame("<textarea>\n</pre>\n\nText </textarea>\n\n## Evidence\n\n".self::LINES, $body);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unterminatedBlocksOfKindsTwoToFive(): iterable
    {
        yield 'comment' => ['<!--', '-->'];
        yield 'processing instruction' => ['<?php', '?>'];
        yield 'declaration' => ['<!DOCTYPE html', '>'];
        yield 'CDATA' => ['<![CDATA[', ']]>'];
    }

    #[DataProvider('unterminatedBlocksOfKindsTwoToFive')]
    public function testAnUnterminatedBlockOfKindsTwoToFiveIsClosedBeforeTheResults(string $opening, string $closer): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo($opening."\ntext\n");

        self::assertSame($opening."\ntext\n".$closer."\n\n## Evidence\n\n".self::LINES, $body);
    }

    public function testAnUnterminatedCommentInsideTheEvidenceSectionIsClosedBeforeTheResults(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("## Evidence\n\n<!--\nnote");

        self::assertSame("## Evidence\n\n<!--\nnote\n-->\n\n".self::LINES, $body);
    }

    public function testAVerticalTabLineDoesNotEndAKindSixBlock(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("<div>\n\x0B\n## Evidence\n\n- E1: shown");

        self::assertSame("<div>\n\x0B\n## Evidence\n\n- E1: shown\n\n## Evidence\n\n".self::LINES, $body);
    }

    public function testAPreEndTagLineOnItsOwnIsParagraphTextSoTheHeadingBelowItIsRead(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("</pre>\n## Evidence\n\n- E1: shown");

        self::assertSame("</pre>\n## Evidence\n\n- E1: shown\n\n".self::LINES, $body);
    }

    public function testASelfClosingPreLineOnItsOwnIsParagraphTextSoTheHeadingBelowItIsRead(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("<PRE/>\n## Evidence\n\n- E1: shown");

        self::assertSame("<PRE/>\n## Evidence\n\n- E1: shown\n\n".self::LINES, $body);
    }

    public function testATextareasOwnEndTagLineStartsNoBlockSoTheEvidenceHeadingBelowItGetsTheResults(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("<textarea>\n</pre>\n</textarea>\n## Evidence\n\n- E1: shown");

        self::assertSame("<textarea>\n</pre>\n</textarea>\n## Evidence\n\n- E1: shown\n\n".self::LINES, $body);
    }

    public function testAVerticalTabLineContinuesAParagraphSoASpanLineBelowItStartsNoBlock(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("Paragraph\n\x0B\n<span>\n## Evidence");

        self::assertSame("Paragraph\n\x0B\n<span>\n## Evidence\n\n".self::LINES, $body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function endTagsWrittenOtherwise(): iterable
    {
        yield 'space before the bracket' => ['</textarea >'];
        yield 'tab before the bracket' => ["</textarea\t>"];
        yield 'upper case' => ['</TEXTAREA>'];
    }

    #[DataProvider('endTagsWrittenOtherwise')]
    public function testAnOwnEndTagWrittenOtherwiseClosesTheElementSoNoSecondOneIsAppended(string $end): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("<textarea>\nExample.\n</pre>\n".$end);

        self::assertSame("<textarea>\nExample.\n</pre>\n".$end."\n\n## Evidence\n\n".self::LINES, $body);
    }

    public function testTheTrailingSpacesOfAnOpenFencesLastLineAreKeptBeforeItsCloser(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("```\nexample  \n");

        self::assertSame("```\nexample  \n```\n\n## Evidence\n\n".self::LINES, $body);
    }

    public function testTheTrailingSpacesOfAnOpenFencesLastLineInsideTheEvidenceSectionAreKeptBeforeItsCloser(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("## Evidence\n\n```\nexample  \n");

        self::assertSame("## Evidence\n\n```\nexample  \n```\n\n".self::LINES, $body);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function otherBlocks(): iterable
    {
        yield 'comment' => ['<!--', '-->'];
        yield 'processing instruction' => ['<?php', '?>'];
        yield 'declaration' => ['<!DOCTYPE', '>'];
        yield 'cdata' => ['<![CDATA[', ']]>'];
    }

    #[DataProvider('otherBlocks')]
    public function testAnEvidenceHeadingInsideAKindTwoToFiveBlockIsHiddenUntilItsEndMarker(string $opening, string $end): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo($opening."\n## Evidence\n".$end."\n## Evidence\n\n- E1: shown");

        self::assertSame($opening."\n## Evidence\n".$end."\n## Evidence\n\n- E1: shown\n\n".self::LINES, $body);
    }

    public function testAKindOneBlockThatEndsOnItsOwnLineHidesNothingAfterIt(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("<pre>x</pre>\n## Evidence\n\n- E1: shown");

        self::assertSame("<pre>x</pre>\n## Evidence\n\n- E1: shown\n\n".self::LINES, $body);
    }

    public function testAnHtmlBlockOpenerInsideACodeFenceStartsNoBlock(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("```\n<div>\n```\n## Evidence\n\n- E1: shown");

        self::assertSame("```\n<div>\n```\n## Evidence\n\n- E1: shown\n\n".self::LINES, $body);
    }

    public function testAPathThatCouldBreakOutOfACodeSpanIsNotShown(): void
    {
        $runs = new RegressionRuns(new Paths('tests/`x`.php', 'tests/FooTest.php'), RegressionRun::Failed, RegressionRun::Passed);

        $body = new RegressionEvidence($runs)->addedTo('Text.');

        self::assertStringContainsString('- Regression tests: a test whose path is not shown, `tests/FooTest.php`', $body);
    }

    public function testARunThatDidNotHappenIsNamedAsNotRun(): void
    {
        $body = new RegressionEvidence(new RegressionRuns(new Paths('tests/FooTest.php'), null, RegressionRun::Passed))->addedTo('Text.');

        self::assertStringContainsString('- On the base: not run', $body);
    }

    public function testASubheadingInsideEvidenceKeepsTheResultsAtTheEndOfTheSection(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("## Evidence\n\n### E1\n\nShown.\n\n## Next");

        self::assertSame("## Evidence\n\n### E1\n\nShown.\n\n".self::LINES."\n\n## Next", $body);
    }

    public function testAHigherLevelHeadingEndsTheEvidenceSection(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("## Evidence\n\nShown.\n\n# Appendix");

        self::assertSame("## Evidence\n\nShown.\n\n".self::LINES."\n\n# Appendix", $body);
    }

    public function testAClosedFenceWithCrlfLineEndsGetsNoExtraFence(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("```\r\ncode\r\n```\r\n\r\nSummary.");

        self::assertSame("```\ncode\n```\n\nSummary.\n\n## Evidence\n\n".self::LINES, $body);
    }

    public function testAnEvidenceHeadingWithCrlfLineEndsIsTheEvidenceSection(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("## Evidence\r\n\r\nShown.\r\n");

        self::assertSame("## Evidence\n\nShown.\n\n".self::LINES, $body);
    }

    public function testAnUnclosedFenceWithBareCrLineEndsIsClosedBeforeANewEvidenceHeading(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("Summary.\r\r```text\rsample");

        self::assertSame("Summary.\n\n```text\nsample\n```\n\n## Evidence\n\n".self::LINES, $body);
    }

    public function testAnEvidenceHeadingWithBareCrLineEndsGetsNoSecondSection(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("## Evidence\r\rShown.\r");

        self::assertSame("## Evidence\n\nShown.\n\n".self::LINES, $body);
    }

    public function testACrDelimitedOpenFenceInsideAnLfBodyIsClosedBeforeTheResults(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("## Evidence\n\nShown.\n```\routput");

        self::assertSame("## Evidence\n\nShown.\n```\noutput\n```\n\n".self::LINES, $body);
    }

    public function testAnEvidenceHeadingWithAColonIsTheEvidenceSection(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("## Evidence:\nShown.");

        self::assertSame("## Evidence:\nShown.\n\n".self::LINES, $body);
    }

    public function testAHeadingThatOnlyStartsWithTheWordIsNotTheEvidenceSection(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("## Evidenced\nShown.");

        self::assertSame("## Evidenced\nShown.\n\n## Evidence\n\n".self::LINES, $body);
    }

    public function testAParagraphUnderlinedWithEqualsSignsIsALevelOneEvidenceHeading(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("Evidence\n========\n\n## E1\n\nShown.\n\nAppendix\n===\n\nMore.");

        self::assertSame("Evidence\n========\n\n## E1\n\nShown.\n\n".self::LINES."\n\nAppendix\n===\n\nMore.", $body);
    }

    public function testAParagraphUnderlinedWithHyphensIsALevelTwoEvidenceHeadingThatEndsAtTheNextLevelTwoHeading(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("Summary.\n\nEvidence:\n---\n\n### E1\n\nShown.\n\n## Follow-ups\n\n- none");

        self::assertSame("Summary.\n\nEvidence:\n---\n\n### E1\n\nShown.\n\n".self::LINES."\n\n## Follow-ups\n\n- none", $body);
    }

    public function testASetextHeadingEndsAnAtxEvidenceSectionBeforeItsTextLine(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("## Evidence\n\nShown.\n\nFollow-ups\nand notes\n---\n\n- none");

        self::assertSame("## Evidence\n\nShown.\n\n".self::LINES."\n\nFollow-ups\nand notes\n---\n\n- none", $body);
    }

    public function testAnIndentedEvidenceLineAboveHyphensIsIndentedCodeNotAHeading(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("Summary.\n\n    Evidence\n---\n\nMore.\n\n## Evidence\n\n- E1: shown");

        self::assertSame("Summary.\n\n    Evidence\n---\n\nMore.\n\n## Evidence\n\n- E1: shown\n\n".self::LINES, $body);
    }

    public function testATabIndentedEvidenceLineAboveEqualsSignsIsIndentedCodeNotAHeading(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("Summary.\n\n  \tEvidence\n===\n\n## Evidence\n\n- E1: shown");

        self::assertSame("Summary.\n\n  \tEvidence\n===\n\n## Evidence\n\n- E1: shown\n\n".self::LINES, $body);
    }

    public function testAnIndentedLineThatContinuesAParagraphIsPartOfTheSetextHeading(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("Evidence\n    for this part\n---\n\nShown.");

        self::assertSame("Evidence\n    for this part\n---\n\nShown.\n\n".self::LINES, $body);
    }

    public function testAnEvidenceLineAboveHyphensInsideACodeFenceIsNotAHeading(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("```\nEvidence\n---\n```\n\nText.");

        self::assertSame("```\nEvidence\n---\n```\n\nText.\n\n## Evidence\n\n".self::LINES, $body);
    }

    public function testHyphensAfterABlankLineAreAThematicBreakThatDoesNotEndTheEvidenceSection(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("## Evidence\n\nShown.\n\n---\n\nMore.");

        self::assertSame("## Evidence\n\nShown.\n\n---\n\nMore.\n\n".self::LINES, $body);
    }

    public function testHyphensUnderAListItemAreAThematicBreakThatDoesNotEndTheEvidenceSection(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("## Evidence\n\n- E1: shown\n---\n\nMore.");

        self::assertSame("## Evidence\n\n- E1: shown\n---\n\nMore.\n\n".self::LINES, $body);
    }

    public function testATextLineAfterAThematicBreakCanStillBeASetextHeading(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("Summary.\n\n***\nEvidence\n---\n\nShown.\n\n## Next");

        self::assertSame("Summary.\n\n***\nEvidence\n---\n\nShown.\n\n".self::LINES."\n\n## Next", $body);
    }

    public function testAnOrderedItemNotStartingAtOneInsideAParagraphIsTextSoHyphensUnderlineIt(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("## Evidence\n\nShown.\n\nFollow-ups\n2. Keep the earlier note\n---\n\nMore.");

        self::assertSame("## Evidence\n\nShown.\n\n".self::LINES."\n\nFollow-ups\n2. Keep the earlier note\n---\n\nMore.", $body);
    }

    public function testAnOrderedItemStartingAtOneInsideAParagraphEndsItSoHyphensAreAThematicBreak(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("## Evidence\n\nShown.\n\nFollow-ups\n1. Keep\n---\n\nMore.");

        self::assertSame("## Evidence\n\nShown.\n\nFollow-ups\n1. Keep\n---\n\nMore.\n\n".self::LINES, $body);
    }

    public function testAnOrderedItemBelowAWrappedOrderedItemIsAnItemSoHyphensDoNotSplitTheList(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("## Evidence\n\n1. E1: the test\n   passes\n2. E2: shown\n---\n\n## Follow-ups\n\nNone.");

        self::assertSame("## Evidence\n\n1. E1: the test\n   passes\n2. E2: shown\n---\n\n".self::LINES."\n\n## Follow-ups\n\nNone.", $body);
    }

    public function testAZeroPaddedOrderedItemStartingAtOneEndsAParagraphSoHyphensAreAThematicBreak(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("## Evidence\n\nIntroductory text\n01. First item\n---");

        self::assertSame("## Evidence\n\nIntroductory text\n01. First item\n---\n\n".self::LINES, $body);
    }

    public function testANineDigitOrderedItemStartingAtOneEndsAParagraphSoHyphensAreAThematicBreak(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("## Evidence\n\nIntroductory text\n000000001) First item\n---");

        self::assertSame("## Evidence\n\nIntroductory text\n000000001) First item\n---\n\n".self::LINES, $body);
    }

    public function testAZeroPaddedOrderedItemNotStartingAtOneInsideAParagraphIsTextSoHyphensUnderlineIt(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("## Evidence\n\nShown.\n\nFollow-ups\n02. Keep\n---\n\nMore.");

        self::assertSame("## Evidence\n\nShown.\n\n".self::LINES."\n\nFollow-ups\n02. Keep\n---\n\nMore.", $body);
    }

    public function testATenDigitOrderedMarkerInsideAParagraphIsTextSoHyphensUnderlineIt(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("## Evidence\n\nShown.\n\nFollow-ups\n0000000001. Keep\n---\n\nMore.");

        self::assertSame("## Evidence\n\nShown.\n\n".self::LINES."\n\nFollow-ups\n0000000001. Keep\n---\n\nMore.", $body);
    }

    public function testAnEmptyOrderedItemInsideAParagraphIsTextSoHyphensUnderlineIt(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("## Evidence\n\nShown.\n\nFollow-ups\n1.\n---\n\nMore.");

        self::assertSame("## Evidence\n\nShown.\n\n".self::LINES."\n\nFollow-ups\n1.\n---\n\nMore.", $body);
    }

    public function testAnEmptyBulletItemFollowedBySpacesInsideAParagraphIsTextSoHyphensUnderlineIt(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("## Evidence\n\nShown.\n\nFollow-ups\n* \t\n---\n\nMore.");

        self::assertSame("## Evidence\n\nShown.\n\n".self::LINES."\n\nFollow-ups\n* \t\n---\n\nMore.", $body);
    }

    public function testAnEmptyItemBelowAWrappedItemIsAnItemSoHyphensDoNotSplitTheList(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("## Evidence\n\n1. E1: the test\n   passes\n2.\n---\n\n## Follow-ups\n\nNone.");

        self::assertSame("## Evidence\n\n1. E1: the test\n   passes\n2.\n---\n\n".self::LINES."\n\n## Follow-ups\n\nNone.", $body);
    }

    public function testACrlfBodyWithASetextEvidenceHeadingGetsTheResultsUnderIt(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("Evidence\r\n--------\r\n\r\nShown.\r\n\r\n## Next");

        self::assertStringContainsString("Shown.\n\n".self::LINES."\n\n## Next", str_replace("\r\n", "\n", $body));
    }

    private function runs(): RegressionRuns
    {
        return new RegressionRuns(new Paths('tests/FooTest.php'), RegressionRun::Failed, RegressionRun::Passed);
    }
}
