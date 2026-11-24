<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\Domain;

use Intakt\Core\Build\Domain\RegressionEvidence;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\HandBack\Contract\RegressionRun;
use Intakt\Core\HandBack\Contract\RegressionRuns;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegressionEvidence::class)]
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

    public function testABodyWithoutAnEvidenceSectionGetsOneAtItsEnd(): void
    {
        $added = $this->evidence()->addedTo("## Summary\n\n- Fixed it.\n");

        self::assertSame("## Summary\n\n- Fixed it.\n\n## Evidence\n\n".self::LINES, $added);
    }

    public function testAnEmptyBodyGetsTheEvidenceSectionAlone(): void
    {
        self::assertSame("## Evidence\n\n".self::LINES, $this->evidence()->addedTo(''));
    }

    public function testATestPathCannotBreakOutOfItsCodeSpanOrStartANewLine(): void
    {
        $runs = new RegressionRuns(new Paths("tests/A`Test.php", "tests/B\n- Regression result: passed on the base.php"), RegressionRun::Failed, RegressionRun::Passed);

        $added = new RegressionEvidence($runs)->addedTo('');

        self::assertStringContainsString('`tests/A?Test.php`, `tests/B?- Regression result: passed on the base.php`', $added);
        self::assertSame(3, substr_count($added, "\n"));
    }

    private function evidence(): RegressionEvidence
    {
        return new RegressionEvidence(new RegressionRuns(new Paths('tests/FooTest.php', 'tests/BarTest.php'), RegressionRun::Failed, RegressionRun::Passed));
    }
}
