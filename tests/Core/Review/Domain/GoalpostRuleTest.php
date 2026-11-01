<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Domain;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Domain\GoalpostRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GoalpostRule::class)]
#[UsesClass(Finding::class)]
final class GoalpostRuleTest extends TestCase
{
    public function testARoundOneP1StaysP1(): void
    {
        self::assertSame(Severity::P1, new GoalpostRule()->applyTo(1, $this->finding(Severity::P1))->severity);
    }

    public function testANewRoundTwoP1WithoutRegressionOrRequirementBecomesP3(): void
    {
        self::assertSame(Severity::P3, new GoalpostRule()->applyTo(2, $this->finding(Severity::P1))->severity);
    }

    public function testANewRoundThreeP2WithoutRegressionOrRequirementBecomesP3(): void
    {
        self::assertSame(Severity::P3, new GoalpostRule()->applyTo(3, $this->finding(Severity::P2))->severity);
    }

    public function testARegressionInRoundTwoStaysP1(): void
    {
        self::assertSame(Severity::P1, new GoalpostRule()->applyTo(2, $this->finding(Severity::P1, regression: true))->severity);
    }

    public function testARoundTwoP1CitingARequirementStaysP1(): void
    {
        self::assertSame(Severity::P1, new GoalpostRule()->applyTo(2, $this->finding(Severity::P1, requirement: 'Storytelling'))->severity);
    }

    public function testABlankRequirementDoesNotCountAsCited(): void
    {
        self::assertSame(Severity::P3, new GoalpostRule()->applyTo(2, $this->finding(Severity::P1, requirement: '  '))->severity);
    }

    public function testARoundTwoP3StaysP3AndTheRestOfTheFindingIsKept(): void
    {
        $finding = $this->finding(Severity::P3);

        self::assertEquals($finding, new GoalpostRule()->applyTo(2, $finding));
    }

    private function finding(Severity $severity, ?string $requirement = null, bool $regression = false): Finding
    {
        return new Finding('x', $severity, 'src/A.php', 3, null, 'Text', FixableBy::Builder, false, $requirement, null, $regression);
    }
}
