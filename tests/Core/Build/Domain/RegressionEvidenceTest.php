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
#[UsesClass(RegressionRun::class)]
#[UsesClass(Paths::class)]
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

    public function testAnIndentedEvidenceLineFollowedByDashesIsCodeAndTheRealEvidenceSectionAtTheEndGetsTheResults(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("Text.\n\n    Evidence\n---\n\n## Evidence\n\nShown.");

        self::assertSame("Text.\n\n    Evidence\n---\n\n## Evidence\n\nShown.\n\n".self::LINES, $body);
    }

    public function testAParagraphLineUnderlinedWithEqualsIsALevelOneEvidenceHeading(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("Evidence\n=====\nShown.");

        self::assertSame("Evidence\n=====\nShown.\n\n".self::LINES, $body);
    }

    public function testAParagraphLineUnderlinedWithDashesIsALevelTwoEvidenceHeading(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("Evidence\n-----\nShown.\n\n# Next");

        self::assertSame("Evidence\n-----\nShown.\n\n".self::LINES."\n\n# Next", $body);
    }

    public function testASetextHeadingOfTheSameLevelEndsTheEvidenceSectionBeforeItsText(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("## Evidence\nShown.\n\nFollow-ups\n---\nnone");

        self::assertSame("## Evidence\nShown.\n\n".self::LINES."\n\nFollow-ups\n---\nnone", $body);
    }

    public function testAnEvidenceHeadingInsideAKindSixHtmlBlockIsNotTheEvidenceSection(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("<div>\n## Evidence\n\nText.");

        self::assertSame("<div>\n## Evidence\n\nText.\n\n## Evidence\n\n".self::LINES, $body);
    }

    public function testAnEvidenceHeadingInsideALoneSpanTagBlockIsNotTheEvidenceSection(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("<span>\n## Evidence\n\nText.");

        self::assertSame("<span>\n## Evidence\n\nText.\n\n## Evidence\n\n".self::LINES, $body);
    }

    public function testAnEvidenceHeadingInsideALoneClosingTagBlockIsNotTheEvidenceSection(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("</span>\n## Evidence\n\nText.");

        self::assertSame("</span>\n## Evidence\n\nText.\n\n## Evidence\n\n".self::LINES, $body);
    }

    public function testALoneTagLineAfterAParagraphLineDoesNotStartAnHtmlBlock(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("Text\n<span>\n## Evidence\nShown.");

        self::assertSame("Text\n<span>\n## Evidence\nShown.\n\n".self::LINES, $body);
    }

    public function testAPreBlockEndsAtAScriptEndTagNotAtABlankLine(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("<pre>\n\n## Evidence\n</script>\n\n## Evidence\nShown.");

        self::assertSame("<pre>\n\n## Evidence\n</script>\n\n## Evidence\nShown.\n\n".self::LINES, $body);
    }

    public function testAnEvidenceHeadingInsideAnHtmlCommentIsNotTheEvidenceSection(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("<!--\n\n## Evidence\n-->\nText.");

        self::assertSame("<!--\n\n## Evidence\n-->\nText.\n\n## Evidence\n\n".self::LINES, $body);
    }

    public function testAnEvidenceHeadingAfterASingleLineHtmlCommentIsTheEvidenceSection(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("<!-- note -->\n## Evidence\nShown.");

        self::assertSame("<!-- note -->\n## Evidence\nShown.\n\n".self::LINES, $body);
    }

    public function testAnUnderlineInsideAFenceDoesNotMakeASetextHeading(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("```\nEvidence\n---\n```\nText.");

        self::assertSame("```\nEvidence\n---\n```\nText.\n\n## Evidence\n\n".self::LINES, $body);
    }

    public function testADashLineAfterABlankLineIsNotASetextUnderline(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("Text.\n\n---\n## Evidence\nShown.");

        self::assertSame("Text.\n\n---\n## Evidence\nShown.\n\n".self::LINES, $body);
    }

    public function testAnOpenCommentAtTheEndIsClosedBeforeTheResultsAreAdded(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("Text.\n\n<!-- note\nstill open");

        self::assertSame("Text.\n\n<!-- note\nstill open\n-->\n\n## Evidence\n\n".self::LINES, $body);
    }

    public function testAnOpenPreBlockInTheEvidenceSectionIsClosedBeforeTheResultsAreAdded(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("## Evidence\n<pre>\ncode");

        self::assertSame("## Evidence\n<pre>\ncode\n</pre>\n\n".self::LINES, $body);
    }

    public function testAnOpenProcessingInstructionAtTheEndIsClosedBeforeTheResultsAreAdded(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo('<?php');

        self::assertSame("<?php\n?>\n\n## Evidence\n\n".self::LINES, $body);
    }

    public function testAnEvidenceHeadingInsideADeclarationIsNotTheEvidenceSection(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("<!DOCTYPE\n\n## Evidence\n>\nText.");

        self::assertSame("<!DOCTYPE\n\n## Evidence\n>\nText.\n\n## Evidence\n\n".self::LINES, $body);
    }

    public function testAnOpenCdataBlockAtTheEndIsClosedBeforeTheResultsAreAdded(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("<![CDATA[\n## Evidence");

        self::assertSame("<![CDATA[\n## Evidence\n]]>\n\n## Evidence\n\n".self::LINES, $body);
    }

    public function testAnIndentedLineWithATabAfterSpacesIsCodeNotASetextHeading(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("  \tEvidence\n---\n## Evidence\nShown.");

        self::assertSame("  \tEvidence\n---\n## Evidence\nShown.\n\n".self::LINES, $body);
    }

    public function testAnIndentedContinuationLineBelongsToTheSetextHeadingTitle(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("Evidence\n    details\n---\nShown.");

        self::assertSame("Evidence\n    details\n---\nShown.\n\n".self::LINES, $body);
    }

    public function testAVerticalTabLineDoesNotEndAnHtmlBlock(): void
    {
        $body = new RegressionEvidence($this->runs())->addedTo("<div>\n\x0b\n## Evidence\n \t\n## Notes\nLater.");

        self::assertSame("<div>\n\x0b\n## Evidence\n \t\n## Notes\nLater.\n\n## Evidence\n\n".self::LINES, $body);
    }

    private function runs(): RegressionRuns
    {
        return new RegressionRuns(new Paths('tests/FooTest.php'), RegressionRun::Failed, RegressionRun::Passed);
    }
}
