<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Domain;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\GoalpostRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GoalpostRule::class)]
#[UsesClass(Finding::class)]
#[UsesClass(Verdict::class)]
final class GoalpostRuleTest extends TestCase
{
    // The expected finding is built through the constructor, not withSeverity, so a row fails when the rule and that helper agree on a wrong severity; the whole finding is compared, so a row also proves the rule keeps every other field.
    #[DataProvider('roundsAndSeverities')]
    public function testAFindingKeepsTheSeverityItsRoundAllowsAndTheRestOfItsFields(int $round, Finding $finding, Severity $severity): void
    {
        $expected = self::finding($severity, $finding->requirement, $finding->regression);

        self::assertEquals($expected, new GoalpostRule()->applyTo($round, $finding));
    }

    /**
     * @return iterable<string, array{int, Finding, Severity}>
     */
    public static function roundsAndSeverities(): iterable
    {
        yield 'a round one P1 stays P1' => [1, self::finding(Severity::P1), Severity::P1];
        yield 'a new round two P1 without regression or requirement becomes P3' => [2, self::finding(Severity::P1), Severity::P3];
        yield 'a new round three P2 without regression or requirement becomes P3' => [3, self::finding(Severity::P2), Severity::P3];
        yield 'a regression in round two stays P1' => [2, self::finding(Severity::P1, regression: true), Severity::P1];
        yield 'a round two P1 citing a requirement stays P1' => [2, self::finding(Severity::P1, requirement: 'Storytelling'), Severity::P1];
        yield 'a blank requirement does not count as cited' => [2, self::finding(Severity::P1, requirement: '  '), Severity::P3];
        yield 'a round two P3 stays P3 and the rest of the finding is kept' => [2, self::finding(Severity::P3), Severity::P3];
    }

    public function testAVerdictCarriesANewRoundTwoP1AsP3(): void
    {
        $verdict = new GoalpostRule()->applyToVerdict(2, $this->verdict(self::finding(Severity::P1)), []);

        self::assertSame(Severity::P3, $verdict->findings[0]->severity);
    }

    public function testARepeatInAVerdictKeepsTheSeverityOfTheOpenFinding(): void
    {
        $open = [$this->repeat('F2', Severity::P3)];

        $verdict = new GoalpostRule()->applyToVerdict(3, $this->verdict($this->repeat('F2', Severity::P1)), $open);

        self::assertSame(Severity::P3, $verdict->findings[0]->severity);
    }

    public function testARepeatInAVerdictIsNotLoweredBelowTheOpenFinding(): void
    {
        $open = [$this->repeat('F1', Severity::P1)];

        $verdict = new GoalpostRule()->applyToVerdict(2, $this->verdict($this->repeat('F1', Severity::P3)), $open);

        self::assertSame(Severity::P1, $verdict->findings[0]->severity);
    }

    public function testARepeatOfAnIdTheReviewerWasNotShownCountsAsNew(): void
    {
        $verdict = new GoalpostRule()->applyToVerdict(2, $this->verdict($this->repeat('F9', Severity::P1)), []);

        self::assertSame(Severity::P3, $verdict->findings[0]->severity);
    }

    private static function finding(Severity $severity, ?string $requirement = null, bool $regression = false): Finding
    {
        return new Finding('x', $severity, 'src/A.php', 3, null, 'Text', FixableBy::Builder, false, $requirement, null, $regression);
    }

    private function verdict(Finding $finding): Verdict
    {
        return new Verdict(Decision::Changes, [$finding], [], []);
    }

    private function repeat(string $id, Severity $severity): Finding
    {
        return new Finding($id, $severity, 'src/A.php', 3, null, 'Text', FixableBy::Builder, true, null, null);
    }
}
