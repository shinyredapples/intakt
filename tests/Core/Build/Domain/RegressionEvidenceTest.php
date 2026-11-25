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

    private function runs(): RegressionRuns
    {
        return new RegressionRuns(new Paths('tests/FooTest.php'), RegressionRun::Failed, RegressionRun::Passed);
    }
}
