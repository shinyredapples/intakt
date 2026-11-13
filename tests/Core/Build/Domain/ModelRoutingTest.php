<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\Domain;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Build\Contract\BuildFacts;
use Intakt\Core\Build\Contract\TierChoice;
use Intakt\Core\Build\Contract\TierReason;
use Intakt\Core\Build\Domain\ModelRouting;
use Intakt\Core\Build\Domain\RoutingRules;
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

    public function testABuildWithoutPathsOrSensitiveRulesGetsTheDefault(): void
    {
        $choice = new ModelRouting()->choose(new RoutingRules(Tier::Light, []), new BuildFacts(null, []));

        self::assertSame(Tier::Light, $choice->tier);
    }

    private function choose(BuildFacts $facts): TierChoice
    {
        return new ModelRouting()->choose(new RoutingRules(Tier::Standard, ['src/**/Security/**']), $facts);
    }
}
