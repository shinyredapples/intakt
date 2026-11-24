<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\Domain;

use Intakt\Core\Build\Domain\ReadableLines;
use Intakt\Core\Build\Domain\RegressionEvidence;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\HandBack\Contract\RegressionRun;
use Intakt\Core\HandBack\Contract\RegressionRuns;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegressionEvidence::class)]
#[UsesClass(ReadableLines::class)]
#[UsesClass(RegressionRuns::class)]
#[UsesClass(Paths::class)]
final class RegressionEvidenceTest extends TestCase
{
    private const string LINES = "- Regression tests, run by Intakt: `tests/FooTest.php`, `tests/BarTest.php`\n- Regression result: failed on the base, passed on the head";

    public function testTheResultsCloseTheBodysEvidenceSectionBeforeTheNextSection(): void
    {
        $body = "## Summary\n\n- Fixed it.\n\n## Evidence\n\n- E1: a test: `FooTest`\n\n## Follow-ups\n\n- None.";

        $added = $this->evidence()->addedTo($body);

        self::assertSame("## Summary\n\n- Fixed it.\n\n## Evidence\n\n- E1: a test: `FooTest`\n".self::LINES."\n\n## Follow-ups\n\n- None.", $added);
    }

    public function testTheResultsCloseAnEvidenceSectionThatEndsTheBody(): void
    {
        $added = $this->evidence()->addedTo("## Evidence\n\n- E1: a test\n");

        self::assertSame("## Evidence\n\n- E1: a test\n".self::LINES, $added);
    }

    public function testASubheadingInsideTheEvidenceSectionStaysInIt(): void
    {
        $added = $this->evidence()->addedTo("## Evidence\n\n### E1\n\n- a test\n\n## Decisions\n\n- D1: here");

        self::assertSame("## Evidence\n\n### E1\n\n- a test\n".self::LINES."\n\n## Decisions\n\n- D1: here", $added);
    }

    public function testTheEvidenceStepsSectionIsNotTheEvidenceSection(): void
    {
        $added = $this->evidence()->addedTo("## Evidence steps\n\nE1 test Foo");

        self::assertSame("## Evidence steps\n\nE1 test Foo\n\n## Evidence\n\n".self::LINES, $added);
    }

    public function testAnEvidenceHeadingInsideACodeFenceIsNotTheEvidenceSection(): void
    {
        $added = $this->evidence()->addedTo("## Summary\n\n```\n## Evidence\n```");

        self::assertSame("## Summary\n\n```\n## Evidence\n```\n\n## Evidence\n\n".self::LINES, $added);
    }

    public function testAHeadingInsideACodeFenceOfTheEvidenceSectionDoesNotEndIt(): void
    {
        $added = $this->evidence()->addedTo("## Evidence\n\n~~~\n## not a heading\n~~~\n\n## Follow-ups");

        self::assertSame("## Evidence\n\n~~~\n## not a heading\n~~~\n".self::LINES."\n\n## Follow-ups", $added);
    }

    public function testATildeLineInsideABacktickFenceDoesNotCloseIt(): void
    {
        $added = $this->evidence()->addedTo("## Evidence\n\n```\n~~~\n## inside\n```\n\n## Next");

        self::assertSame("## Evidence\n\n```\n~~~\n## inside\n```\n".self::LINES."\n\n## Next", $added);
    }

    public function testAThreeBacktickLineInsideAFourBacktickFenceDoesNotCloseIt(): void
    {
        $added = $this->evidence()->addedTo("## Evidence\n\n- E1: example output\n\n````text\n```\n## Example\n\n````\n\n## Next");

        self::assertSame("## Evidence\n\n- E1: example output\n\n````text\n```\n## Example\n\n````\n".self::LINES."\n\n## Next", $added);
    }

    public function testALongerFenceOfTheSameCharacterClosesTheFence(): void
    {
        $added = $this->evidence()->addedTo("## Evidence\n\n```\n## inside\n`````\n\n## Next");

        self::assertSame("## Evidence\n\n```\n## inside\n`````\n".self::LINES."\n\n## Next", $added);
    }

    public function testAFenceTheBodyNeverClosesIsClosedBeforeTheNewEvidenceSection(): void
    {
        $added = $this->evidence()->addedTo("## Summary\n\n```\ncode");

        self::assertSame("## Summary\n\n```\ncode\n```\n\n## Evidence\n\n".self::LINES, $added);
    }

    public function testAnUnclosedFenceAtTheEndOfTheEvidenceSectionIsClosedBeforeTheResults(): void
    {
        $added = $this->evidence()->addedTo("## Evidence\n\n- E1: output\n\n~~~~\nout\n~~~");

        self::assertSame("## Evidence\n\n- E1: output\n\n~~~~\nout\n~~~\n~~~~\n".self::LINES, $added);
    }

    public function testAnUnclosedFenceInAListItemIsClosedAtTheItemsIndent(): void
    {
        $added = $this->evidence()->addedTo("## Evidence\n\n- E1: output\n  ```\n  out");

        self::assertSame("## Evidence\n\n- E1: output\n  ```\n  out\n  ```\n".self::LINES, $added);
    }

    public function testAnUnclosedCommentIsClosedBeforeTheResults(): void
    {
        $added = $this->evidence()->addedTo("## Evidence\n\n- E1: output\n<!-- a note");

        self::assertSame("## Evidence\n\n- E1: output\n<!-- a note\n-->\n".self::LINES, $added);
    }

    public function testAnEvidenceHeadingInsideACommentIsNotTheEvidenceSection(): void
    {
        $added = $this->evidence()->addedTo("<!--\n## Evidence\n-->\n## Summary");

        self::assertSame("<!--\n## Evidence\n-->\n## Summary\n\n## Evidence\n\n".self::LINES, $added);
    }

    public function testABodyWithWindowsLineEndingsIsReadLineByLine(): void
    {
        $added = $this->evidence()->addedTo("## Evidence\r\n\r\n- E1: a test\r\n\r\n## Next");

        self::assertSame("## Evidence\n\n- E1: a test\n".self::LINES."\n\n## Next", $added);
    }

    public function testABodyWithoutAnEvidenceSectionGetsOneAtItsEnd(): void
    {
        $added = $this->evidence()->addedTo("## Summary\n\n- Fixed it.\n");

        self::assertSame("## Summary\n\n- Fixed it.\n\n## Evidence\n\n".self::LINES, $added);
    }

    public function testAnEmptyBodyGetsTheEvidenceSectionAlone(): void
    {
        self::assertSame("## Evidence\n\n".self::LINES, $this->evidence()->addedTo(''));
    }

    public function testAnIndentedTopLevelHeadingEndsTheEvidenceSection(): void
    {
        $added = $this->evidence()->addedTo("## Evidence\n\n- E1: verified\n\n ## Notes\n\nOther notes.");

        self::assertSame("## Evidence\n\n- E1: verified\n".self::LINES."\n\n ## Notes\n\nOther notes.", $added);
    }

    public function testAHeadingIndentedIntoAListItemStaysInTheEvidenceSection(): void
    {
        $added = $this->evidence()->addedTo("## Evidence\n\n- E1: verified\n\n  ## Output\n\n## Notes");

        self::assertSame("## Evidence\n\n- E1: verified\n\n  ## Output\n".self::LINES."\n\n## Notes", $added);
    }

    public function testAHeadingFourSpacesInIsCodeAndStaysInTheEvidenceSection(): void
    {
        $added = $this->evidence()->addedTo("## Evidence\n\n    ## Output\n\n## Notes");

        self::assertSame("## Evidence\n\n    ## Output\n".self::LINES."\n\n## Notes", $added);
    }

    public function testAnIndentedEvidenceHeadingWithClosingHashesIsTheEvidenceSection(): void
    {
        $added = $this->evidence()->addedTo("   ## Evidence ##\n\n- E1: verified\n\n## Notes");

        self::assertSame("   ## Evidence ##\n\n- E1: verified\n".self::LINES."\n\n## Notes", $added);
    }

    public function testAnUnderlinedEvidenceHeadingIsTheEvidenceSection(): void
    {
        $added = $this->evidence()->addedTo("Evidence\n--------\n\n- E1: verified");

        self::assertSame("Evidence\n--------\n\n- E1: verified\n".self::LINES, $added);
    }

    public function testAnUnderlinedHeadingEndsTheEvidenceSectionAtItsFirstLine(): void
    {
        $added = $this->evidence()->addedTo("## Evidence\n\n- E1: verified\n\nFurther\nnotes\n=====\n\nOther notes.");

        self::assertSame("## Evidence\n\n- E1: verified\n".self::LINES."\n\nFurther\nnotes\n=====\n\nOther notes.", $added);
    }

    public function testARuleBelowAListItemDoesNotEndTheEvidenceSection(): void
    {
        $added = $this->evidence()->addedTo("## Evidence\n\n- E1: verified\n---\ntext");

        self::assertSame("## Evidence\n\n- E1: verified\n---\ntext\n".self::LINES, $added);
    }

    public function testAnIndentedEvidenceLineAboveARuleIsCodeAndNotTheEvidenceSection(): void
    {
        $added = $this->evidence()->addedTo("## Summary\n\n    Evidence\n---\n\n## Evidence\n\n- E1: verified");

        self::assertSame("## Summary\n\n    Evidence\n---\n\n## Evidence\n\n- E1: verified\n".self::LINES, $added);
    }

    public function testAHeadingInsideABlockALoneOpenTagStartsDoesNotEndTheEvidenceSection(): void
    {
        $added = $this->evidence()->addedTo("## Evidence\n\n<span>\n## Example\n</span>\n\n## Notes");

        self::assertSame("## Evidence\n\n<span>\n## Example\n</span>\n\n".self::LINES."\n\n## Notes", $added);
    }

    public function testALoneOpenTagWithAttributesStartsABlock(): void
    {
        $added = $this->evidence()->addedTo("## Evidence\n\n<span class=\"log\" hidden>\n## Example\n\n## Notes");

        self::assertSame("## Evidence\n\n<span class=\"log\" hidden>\n## Example\n\n".self::LINES."\n\n## Notes", $added);
    }

    public function testAHeadingInsideABlockALoneClosingTagStartsDoesNotEndTheEvidenceSection(): void
    {
        $added = $this->evidence()->addedTo("## Evidence\n\n</span>\n## Example\n\n## Notes");

        self::assertSame("## Evidence\n\n</span>\n## Example\n\n".self::LINES."\n\n## Notes", $added);
    }

    public function testALoneTagInsideAParagraphStartsNoBlock(): void
    {
        $added = $this->evidence()->addedTo("## Evidence\n\n- E1: verified\n<span>\n## Notes");

        self::assertSame("## Evidence\n\n- E1: verified\n<span>\n".self::LINES."\n\n## Notes", $added);
    }

    public function testAPreformattedBlockEndsAtAnyOfTheRawEndTags(): void
    {
        $added = $this->evidence()->addedTo("## Evidence\n\n<pre>\nx</script>\n## Notes");

        self::assertSame("## Evidence\n\n<pre>\nx</script>\n".self::LINES."\n\n## Notes", $added);
    }

    public function testAHeadingInPreformattedHtmlDoesNotEndTheEvidenceSection(): void
    {
        $added = $this->evidence()->addedTo("## Evidence\n\n<pre>\n## Example\n</pre>\n\n## Notes");

        self::assertSame("## Evidence\n\n<pre>\n## Example\n</pre>\n".self::LINES."\n\n## Notes", $added);
    }

    public function testAPreformattedBlockClosedOnItsFirstLineHidesNoHeading(): void
    {
        $added = $this->evidence()->addedTo("## Evidence\n\n<PRE>log</PRE>\n## Notes");

        self::assertSame("## Evidence\n\n<PRE>log</PRE>\n".self::LINES."\n\n## Notes", $added);
    }

    public function testAnUnclosedPreformattedBlockIsClosedBeforeTheResults(): void
    {
        $added = $this->evidence()->addedTo("## Evidence\n\n<pre>\n## log");

        self::assertSame("## Evidence\n\n<pre>\n## log\n</pre>\n".self::LINES, $added);
    }

    public function testTheResultsFollowABlankLineAfterAnHtmlBlockTheSectionEndsIn(): void
    {
        $added = $this->evidence()->addedTo("## Evidence\n\n<details>\n<summary>Log</summary>\n## Example");

        self::assertSame("## Evidence\n\n<details>\n<summary>Log</summary>\n## Example\n\n".self::LINES, $added);
    }

    public function testAnHtmlBlockEndedByABlankLineHidesNoLaterHeading(): void
    {
        $added = $this->evidence()->addedTo("## Evidence\n\n<details>\n\nlog\n\n</details>\n\n## Notes");

        self::assertSame("## Evidence\n\n<details>\n\nlog\n\n</details>\n\n".self::LINES."\n\n## Notes", $added);
    }

    public function testATestPathCannotBreakOutOfItsCodeSpanOrStartANewLine(): void
    {
        $runs = new RegressionRuns(new Paths('tests/A`Test.php', "tests/B\n- Regression result: passed on the base.php"), RegressionRun::Failed, RegressionRun::Passed);

        $added = new RegressionEvidence($runs)->addedTo('');

        self::assertStringContainsString('`tests/A?Test.php`, `tests/B?- Regression result: passed on the base.php`', $added);
        self::assertSame(3, substr_count($added, "\n"));
    }

    private function evidence(): RegressionEvidence
    {
        return new RegressionEvidence(new RegressionRuns(new Paths('tests/FooTest.php', 'tests/BarTest.php'), RegressionRun::Failed, RegressionRun::Passed));
    }
}
