<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\Domain;

use Intakt\Core\HandBack\Domain\CodeSpans;
use Intakt\Core\HandBack\Domain\EvidenceItems;
use Intakt\Core\HandBack\Domain\FencedBlock;
use Intakt\Core\HandBack\Domain\HiddenBlockOpeners;
use Intakt\Core\HandBack\Domain\MarkdownPart;
use Intakt\Core\HandBack\Domain\MarkdownText;
use Intakt\Core\HandBack\Domain\OpenListItems;
use Intakt\Core\HandBack\Domain\ParagraphEnd;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EvidenceItems::class)]
#[UsesClass(MarkdownText::class)]
#[UsesClass(MarkdownPart::class)]
#[UsesClass(CodeSpans::class)]
#[UsesClass(FencedBlock::class)]
#[UsesClass(HiddenBlockOpeners::class)]
#[UsesClass(OpenListItems::class)]
#[UsesClass(ParagraphEnd::class)]
final class EvidenceItemsTest extends TestCase
{
    public function testTheTopLevelItemsUnderAnEvidenceLabelAreCounted(): void
    {
        self::assertSame(2, EvidenceItems::countIn("Build it.\n\nEvidence:\n- E1. A red check.\n- E2. A green check.\n"));
    }

    public function testTheItemsUnderAnEvidenceHeadingAreCounted(): void
    {
        self::assertSame(3, EvidenceItems::countIn("## Acceptance\n- a\n\n## Evidence\n- E1: the test run\n* E2: the output\n1. a link (owner provides)\n"));
    }

    public function testAnItemWithoutABulletThatNamesItsNumberIsCounted(): void
    {
        self::assertSame(2, EvidenceItems::countIn("Evidence:\nE1. the test run\nE2) the output\n"));
    }

    public function testNestedLinesUnderAnItemAreNotItems(): void
    {
        self::assertSame(1, EvidenceItems::countIn("Evidence:\n- E1. Both results:\n  - a red one\n  - a green one\n    continued\n"));
    }

    public function testAParagraphAfterABlankLineEndsTheSection(): void
    {
        self::assertSame(1, EvidenceItems::countIn("Evidence:\n- E1. A check.\n\nOut of scope here:\n- the send-back\n- the ledger event\n"));
    }

    public function testTheNextHeadingOrLabelEndsTheSection(): void
    {
        self::assertSame(1, EvidenceItems::countIn("## Evidence\n- E1\n## Notes\n- not evidence\n"));
        self::assertSame(1, EvidenceItems::countIn("Evidence:\n- E1\nAcceptance:\n- not evidence\n"));
    }

    public function testALineDirectlyAfterAnItemContinuesIt(): void
    {
        self::assertSame(2, EvidenceItems::countIn("Evidence:\n- E1. A long item\nwrapped onto a second line.\n- E2. Another.\n"));
    }

    public function testAnEvidenceHeadingInsideACodeFenceIsNotTheSection(): void
    {
        self::assertSame(0, EvidenceItems::countIn("Example:\n```\n## Evidence\n- E1: the test run\n```\n"));
        self::assertSame(1, EvidenceItems::countIn("```\nEvidence:\n- quoted\n```\nEvidence:\n- E1. real\n"));
    }

    public function testATaskWithoutAnEvidenceSectionHasNoItems(): void
    {
        self::assertSame(0, EvidenceItems::countIn("Build it.\n\n- E1 is mentioned, but not under an Evidence heading\n"));
    }

    public function testWindowsLineBreaksAreLineBreaks(): void
    {
        self::assertSame(2, EvidenceItems::countIn("Evidence:\r\n- E1. one\r\n- E2. two\r\n"));
    }

    public function testAnIntroductoryLabelUnderAnEvidenceHeadingDoesNotEndTheSection(): void
    {
        self::assertSame(1, EvidenceItems::countIn("## Evidence\nShow these results:\n\n- E1. The command output\n"));
    }

    public function testAnIntroductoryLabelUnderAnEvidenceLabelDoesNotEndTheSection(): void
    {
        self::assertSame(1, EvidenceItems::countIn("Evidence:\nShow these results:\n- E1. The command output\n"));
    }

    public function testAParagraphAfterABlankLineUnderAnEvidenceHeadingDoesNotEndTheSection(): void
    {
        self::assertSame(2, EvidenceItems::countIn("## Evidence\n- E1. one\n\nAlso show:\n\n- E2. two\n## Notes\n- not evidence\n"));
    }

    public function testASetextEvidenceHeadingStartsTheSection(): void
    {
        self::assertSame(1, EvidenceItems::countIn("Evidence\n--------\n\n- E1. The command output\n"));
        self::assertSame(1, EvidenceItems::countIn("Evidence\n===\n- E1. one\n\nNotes\n-----\n- not evidence\n"));
    }

    public function testAnEvidenceHeadingWithMoreWordsStartsTheSection(): void
    {
        self::assertSame(1, EvidenceItems::countIn("## Evidence (for this part)\n- E1. one\n"));
    }

    public function testAWordThatOnlyStartsWithEvidenceIsNoHeading(): void
    {
        self::assertSame(0, EvidenceItems::countIn("## Evidenced elsewhere\n- E1. one\n"));
    }

    public function testItemsIndentedByUpToThreeSpacesAreTopLevel(): void
    {
        self::assertSame(2, EvidenceItems::countIn("## Evidence\n  - E1. A red result\n  - E2. A green result\n"));
        self::assertSame(2, EvidenceItems::countIn("## Evidence\n   1. one\n   2. two\n"));
    }

    public function testItemsIndentedToTheContentOfAnIndentedItemAreNested(): void
    {
        self::assertSame(1, EvidenceItems::countIn("## Evidence\n  - E1. Both results:\n    - a red one\n"));
        self::assertSame(1, EvidenceItems::countIn("## Evidence\n1. E1. Both results:\n   - a red one\n"));
    }

    public function testALineIndentedByFourSpacesWithoutAListIsNoItem(): void
    {
        self::assertSame(0, EvidenceItems::countIn("## Evidence\n    - code, not an item\n"));
    }

    public function testAThematicBreakIsNoItem(): void
    {
        self::assertSame(1, EvidenceItems::countIn("## Evidence\n- E1. one\n\n* * *\n"));
    }

    public function testAWrappedLowerCaseLineEndingInAColonContinuesTheItem(): void
    {
        self::assertSame(2, EvidenceItems::countIn("Evidence:\n- E1. The results\nof:\n- E2. Another.\n"));
    }

    public function testAFenceClosesOnlyWithItsOwnMarker(): void
    {
        self::assertSame(1, EvidenceItems::countIn("~~~markdown\n```\n~~~\n\n## Evidence\n- E1. Red and green results\n"));
        self::assertSame(0, EvidenceItems::countIn("````\n```\n## Evidence\n- E1. quoted\n````\n"));
    }

    public function testAFenceLineWithTextAfterTheMarkerDoesNotCloseIt(): void
    {
        self::assertSame(0, EvidenceItems::countIn("```\n``` php\n## Evidence\n- E1. quoted\n```\n"));
    }

    public function testAnEvidenceSectionInsideAnHtmlCommentIsHidden(): void
    {
        self::assertSame(1, EvidenceItems::countIn("<!--\n## Evidence\n- E1. hidden\n- E2. hidden\n-->\n## Evidence\n- E1. shown\n"));
    }

    public function testAnAtxHeadingIndentedIntoAnItemBelongsToThatItem(): void
    {
        self::assertSame(2, EvidenceItems::countIn("## Evidence\n- E1. first\n\n  ### Details\n\n  More text.\n\n- E2. second"));
    }

    public function testASetextHeadingIndentedIntoAnItemBelongsToThatItem(): void
    {
        self::assertSame(2, EvidenceItems::countIn("## Evidence\n- E1. first\n\n  Details\n  -------\n\n- E2. second"));
    }

    public function testAHeadingIndentedIntoAnItemUnderAnEvidenceLabelBelongsToThatItem(): void
    {
        self::assertSame(2, EvidenceItems::countIn("Evidence:\n- E1. first\n\n  ### Details\n\n- E2. second"));
        self::assertSame(2, EvidenceItems::countIn("Evidence:\n- E1. first\n\n  Details\n  =======\n\n- E2. second"));
    }

    public function testAHeadingAtTheContentColumnOfAnOrderedItemBelongsToThatItem(): void
    {
        self::assertSame(2, EvidenceItems::countIn("## Evidence\n1. E1. first\n\n   ### Details\n\n2. E2. second"));
    }

    public function testAHeadingWithoutIndentAfterAnItemEndsTheSection(): void
    {
        self::assertSame(1, EvidenceItems::countIn("## Evidence\n- E1. first\n\n## Notes\n- not evidence"));
        self::assertSame(1, EvidenceItems::countIn("## Evidence\n- E1. first\n\nNotes\n-----\n- not evidence"));
        self::assertSame(1, EvidenceItems::countIn("Evidence:\n- E1. first\n\n## Notes\n- not evidence"));
    }

    public function testAHeadingIndentedLessThanTheContentOfTheItemEndsTheSection(): void
    {
        self::assertSame(1, EvidenceItems::countIn("## Evidence\n- E1. first\n\n ## Notes\n- not evidence"));
        self::assertSame(1, EvidenceItems::countIn("## Evidence\n1. E1. first\n\n  ## Notes\n- not evidence"));
        self::assertSame(1, EvidenceItems::countIn("## Evidence\n1. E1. first\n\n  Notes\n  -----\n- not evidence"));
    }

    public function testAHeadingAfterAParagraphThatEndedTheListEndsTheSection(): void
    {
        self::assertSame(1, EvidenceItems::countIn("## Evidence\n- E1. first\n\nA paragraph.\n\n  ## Notes\n- not evidence"));
    }

    public function testAnItemAfterAFenceOpenedAfterATabbedMarkerIsCounted(): void
    {
        self::assertSame(2, EvidenceItems::countIn("## Evidence\n-\t```\n\tx\n\t```\n- E2. b\n"));
        self::assertSame(2, EvidenceItems::countIn("## Evidence\n-\t```\n\tx\n- E2. b\n"));
    }

    // The tab puts the item's content at column four, so the lines indented two are outside it: the second fence line opens a block of its own that holds the next item, as the reference CommonMark parser renders it.
    public function testAnItemInAFenceOpenedLeftOfATabbedMarkersContentIsNotCounted(): void
    {
        self::assertSame(1, EvidenceItems::countIn("## Evidence\n-\t```\n  x\n  ```\n- E2. b\n"));
        self::assertSame(1, EvidenceItems::countIn("## Evidence\n-   ```\n  x\n  ```\n- E2. b\n"));
    }

    public function testAnItemWithATabAfterItsMarkerIsCounted(): void
    {
        self::assertSame(1, EvidenceItems::countIn("## Evidence\n-\tE1. output"));
        self::assertSame(2, EvidenceItems::countIn("## Evidence\n1.\tE1. output\n\n\tmore of it\n2.\tE2. second"));
    }

    public function testAnUnclosedFenceInsideAnItemEndsWithThatItem(): void
    {
        self::assertSame(2, EvidenceItems::countIn("## Evidence\n- E1. output\n\n  ```\n  example\n- E2. second result"));
    }

    public function testAnItemThatOpensAFenceOnItsMarkerLineIsCounted(): void
    {
        self::assertSame(2, EvidenceItems::countIn("## Evidence\n- ```\n  code\n  ```\n- E2. b\n"));
    }

    public function testAListAfterALabelUnderAnEvidenceHeadingCountsAsEvidenceItems(): void
    {
        self::assertSame(3, EvidenceItems::countIn("## Evidence\n- E1\n- E2\n\nAcceptance:\n- the third item\n"));
    }

    public function testOnlyTheFirstEvidenceSectionCounts(): void
    {
        self::assertSame(1, EvidenceItems::countIn("## Evidence\n- E1\n\n## Evidence\n- E1\n- E2\n"));
    }

    public function testAnItemWhoseMarkerLineIsEmptyIsCounted(): void
    {
        self::assertSame(2, EvidenceItems::countIn("## Evidence\n-\n  Show the test output.\n- Show the exit code."));
    }

    public function testAnEvidenceHeadingNestedInAnExampleItemIsNotTheSection(): void
    {
        self::assertSame(2, EvidenceItems::countIn("- Example\n\n  ## Evidence\n  - Example output.\n\n## Evidence\n- Real output.\n- Exit code."));
    }

    public function testAHeadingRightAfterTheListEndsTheSection(): void
    {
        self::assertSame(1, EvidenceItems::countIn("## Evidence\n- Show the test output.\n## Notes\n- This is not evidence."));
    }

    public function testAnOrderedItemNotNumberedOneDoesNotInterruptAParagraph(): void
    {
        self::assertSame(1, EvidenceItems::countIn("## Evidence\n- Show the output.\n\nSee step\n10. of the guide.\n"));
    }

    public function testAnAtxHeadingOutdentedFromAnItemEndsTheListWithoutABlankLine(): void
    {
        self::assertSame(1, EvidenceItems::countIn("- Example\n## Notes\n  ## Evidence\n- Show the result."));
    }

    public function testAThematicBreakOutdentedFromAnItemEndsTheListWithoutABlankLine(): void
    {
        self::assertSame(1, EvidenceItems::countIn("- Intro\n***\n  ## Evidence\n  - Show the result."));
    }

    public function testAnEmptyHtmlCommentEndsOnItsOwnLine(): void
    {
        self::assertSame(2, EvidenceItems::countIn("<!-->\n## Evidence\n- E1. a\n- E2. b\n"));
        self::assertSame(1, EvidenceItems::countIn("<!--->\n## Evidence\n- E1. a\n"));
    }

    public function testAnOutdentedCommentBlockEndsTheListWithoutABlankLine(): void
    {
        self::assertSame(1, EvidenceItems::countIn("- Example\n<!-- note -->\n  ## Evidence\n- Show the result."));
    }

    public function testAnOutdentedCommentBlockEndsTheListAfterABlankLine(): void
    {
        self::assertSame(1, EvidenceItems::countIn("- Example\n\n<!-- note -->\n  ## Evidence\n- Show the result."));
    }

    public function testAnOutdentedFenceEndsTheListWithoutABlankLine(): void
    {
        self::assertSame(1, EvidenceItems::countIn("- Example\n~~~\nexample\n~~~\n  ## Evidence\n- Show the result."));
    }

    public function testACommentIndentedIntoTheItemKeepsTheListOpen(): void
    {
        self::assertSame(0, EvidenceItems::countIn("- Example\n  <!-- note -->\n  ## Evidence\n  - Show the result."));
    }

    public function testAHiddenCommentOrFenceAfterABlankLineBetweenLabelItemsKeepsTheLaterItems(): void
    {
        self::assertSame(2, EvidenceItems::countIn("Evidence:\n- E1. a\n\n<!-- note -->\n- E2. b\n"));
        self::assertSame(2, EvidenceItems::countIn("Evidence:\n- E1. a\n\n```\nx\n```\n- E2. b\n"));
        self::assertSame(2, EvidenceItems::countIn("## Evidence\n- E1. a\n\n<!-- note -->\n- E2. b\n"));
    }

    public function testAnOutdentedCommentEndsTheItemWithCrOrCrlfLineBreaksToo(): void
    {
        self::assertSame(1, EvidenceItems::countIn("- Example\r\n<!-- note -->\r\n  ## Evidence\r\n- Show the result."));
        self::assertSame(1, EvidenceItems::countIn("- Example\r<!-- note -->\r  ## Evidence\r- Show the result."));
    }

    public function testACommentOnTheMarkerLineMakesTheItemNonEmptySoItInterruptsTheParagraphAbove(): void
    {
        self::assertSame(1, EvidenceItems::countIn("## Evidence\nIntroductory paragraph.\n- <!-- note -->\n  Show the result."));
        self::assertSame(2, EvidenceItems::countIn("## Evidence\nIntroductory paragraph.\n- <!-- note -->\n  Show the result.\n- E2. Another."));
        self::assertSame(0, EvidenceItems::countIn("## Evidence\nIntroductory paragraph.\n-\n  Not an item."));
    }

    public function testAByteOrderMarkBeforeTheHeadingIsIgnored(): void
    {
        self::assertSame(1, EvidenceItems::countIn("\u{FEFF}## Evidence\n- E1. Show the result.\n"));
    }
}
