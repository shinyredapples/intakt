<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\Domain;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Build\Contract\BuildFacts;
use Intakt\Core\Build\Contract\Setback;
use Intakt\Core\Build\Contract\TierChoice;
use Intakt\Core\Build\Contract\TierReason;
use Intakt\Core\Build\Domain\ModelRouting;
use Intakt\Core\Build\Domain\RoutingRules;
use Intakt\Core\Review\Contract\EscalationOutcome;
use Intakt\Core\Review\Contract\PathGlob;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModelRouting::class)]
#[UsesClass(RoutingRules::class)]
#[UsesClass(BuildFacts::class)]
#[UsesClass(TierChoice::class)]
#[UsesClass(PathGlob::class)]
final class ModelRoutingTest extends TestCase
{
    public function testABuildWithNoLabelAndNoSensitivePathGetsTheConfiguredCheaperTier(): void
    {
        $choice = $this->choose(new BuildFacts(null, ['docs/a.md']));

        self::assertSame(Tier::Standard, $choice->tier);
        self::assertSame(TierReason::Default, $choice->reason);
    }

    public function testABuildLabelledForAStrongTierGetsItFromTheFirstCycle(): void
    {
        $choice = $this->choose(new BuildFacts(Tier::Strong, ['docs/a.md']));

        self::assertSame(Tier::Strong, $choice->tier);
        self::assertSame(TierReason::Label, $choice->reason);
    }

    public function testABuildTouchingASensitivePathGetsTheStrongTier(): void
    {
        $choice = $this->choose(new BuildFacts(null, ['docs/a.md', 'src/Security/Login.php']));

        self::assertSame(Tier::Strong, $choice->tier);
        self::assertSame(TierReason::SensitivePath, $choice->reason);
    }

    public function testABuildTouchingOnlyPathsOutsideTheSensitiveOnesStaysOnTheCheaperTier(): void
    {
        $choice = $this->choose(new BuildFacts(null, ['src/Other/Login.php', 'config/a.yaml']));

        self::assertSame(Tier::Standard, $choice->tier);
    }

    public function testASensitivePathBeatsALabelForALowerTier(): void
    {
        $choice = $this->choose(new BuildFacts(Tier::Light, ['src/Security/Login.php']));

        self::assertSame(Tier::Strong, $choice->tier);
        self::assertSame(TierReason::SensitivePath, $choice->reason);
    }

    public function testABuildAfterAnEarlierFailedAttemptStartsOnTheStrongTier(): void
    {
        $choice = $this->choose(new BuildFacts(null, ['docs/a.md'], Setback::FailedAttempt));

        self::assertSame(Tier::Strong, $choice->tier);
        self::assertSame(TierReason::Setback, $choice->reason);
    }

    public function testAReworkAfterABlockingFindingIsRaisedToTheStrongTier(): void
    {
        $choice = $this->choose(new BuildFacts(null, ['docs/a.md'], Setback::BlockingFinding));

        self::assertSame(Tier::Strong, $choice->tier);
        self::assertSame(TierReason::Setback, $choice->reason);
    }

    public function testAReworkAfterAStallIsRaisedToTheStrongTier(): void
    {
        $choice = $this->choose(new BuildFacts(null, ['docs/a.md'], Setback::Stall));

        self::assertSame(Tier::Strong, $choice->tier);
        self::assertSame(TierReason::Setback, $choice->reason);
    }

    public function testAReworkAfterARoundWithoutAP1StaysOnTheDefaultTier(): void
    {
        $choice = $this->choose(new BuildFacts(null, ['docs/a.md']));

        self::assertSame(Tier::Standard, $choice->tier);
        self::assertSame(TierReason::Default, $choice->reason);
    }

    public function testASetbackTheProjectDoesNotEscalateOnKeepsTheDefaultTier(): void
    {
        $choice = $this->choose(new BuildFacts(null, [], Setback::Stall), [Setback::BlockingFinding]);

        self::assertSame(Tier::Standard, $choice->tier);
    }

    public function testASensitivePathStillWinsOverASetback(): void
    {
        $choice = $this->choose(new BuildFacts(null, ['src/Security/a.php'], Setback::Stall));

        self::assertSame(TierReason::SensitivePath, $choice->reason);
    }

    public function testASetbackRaisesABuildLabelledWithACheaperTier(): void
    {
        $choice = $this->choose(new BuildFacts(Tier::Light, [], Setback::Stall));

        self::assertSame(Tier::Strong, $choice->tier);
        self::assertSame(TierReason::Setback, $choice->reason);
    }

    public function testASetbackKeepsTheLabelReasonWhenTheLabelAlreadyNamesStrong(): void
    {
        $choice = $this->choose(new BuildFacts(Tier::Strong, [], Setback::Stall));

        self::assertSame(TierReason::Label, $choice->reason);
    }

    public function testABuildAfterAConfirmedEscalationRunsOnTheStrongTierEvenWhenTheProjectEscalatesOnNoSetback(): void
    {
        $choice = $this->choose(new BuildFacts(Tier::Light, [], null, EscalationOutcome::Confirmed), []);

        self::assertSame([Tier::Strong, TierReason::ConfirmedEscalation], [$choice->tier, $choice->reason]);
    }

    public function testABuildAfterARejectedEscalationKeepsItsLabelledTier(): void
    {
        $choice = $this->choose(new BuildFacts(Tier::Light, [], null, EscalationOutcome::Rejected));

        self::assertSame([Tier::Light, TierReason::Label], [$choice->tier, $choice->reason]);
    }

    public function testASensitivePathStillWinsOverAConfirmedEscalation(): void
    {
        $choice = $this->choose(new BuildFacts(null, ['src/Security/a.php'], null, EscalationOutcome::Confirmed));

        self::assertSame(TierReason::SensitivePath, $choice->reason);
    }

    public function testAConfirmedEscalationGivesItsReasonEvenWhenTheLabelAlreadyNamesStrong(): void
    {
        $choice = $this->choose(new BuildFacts(Tier::Strong, [], null, EscalationOutcome::Confirmed));

        self::assertSame([Tier::Strong, TierReason::ConfirmedEscalation], [$choice->tier, $choice->reason]);
    }

    public function testFactsAfterAnEscalationKeepTheirLabelPathsAndSetback(): void
    {
        $facts = BuildFacts::fromLabels(['model:light'], ['a.php'], Setback::Stall)->afterEscalation(EscalationOutcome::Confirmed);

        self::assertEquals(new BuildFacts(Tier::Light, ['a.php'], Setback::Stall, EscalationOutcome::Confirmed), $facts);
    }

    public function testABuildWithoutPathsOrSensitiveRulesGetsTheDefault(): void
    {
        $choice = new ModelRouting()->choose(new RoutingRules(Tier::Light, [], []), new BuildFacts(null, []));

        self::assertSame(Tier::Light, $choice->tier);
    }

    /**
     * @param list<Setback>|null $escalateOn
     */
    private function choose(BuildFacts $facts, ?array $escalateOn = null): TierChoice
    {
        return new ModelRouting()->choose(new RoutingRules(Tier::Standard, ['src/**/Security/**'], $escalateOn ?? Setback::cases()), $facts);
    }
}
