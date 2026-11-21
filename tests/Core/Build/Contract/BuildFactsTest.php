<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\Contract;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Build\Contract\BuildFacts;
use Intakt\Core\Build\Contract\Setback;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(BuildFacts::class)]
final class BuildFactsTest extends TestCase
{
    public function testAModelLabelNamesTheTier(): void
    {
        $facts = BuildFacts::fromLabels(['priority:high', 'model:strong'], ['a.php']);

        self::assertSame(Tier::Strong, $facts->labelledTier);
        self::assertSame(['a.php'], $facts->paths);
    }

    public function testAModelLabelNamingAModelInsteadOfATierIsIgnored(): void
    {
        self::assertNull(BuildFacts::fromLabels(['model:claude-opus-5-5'], [])->labelledTier);
    }

    public function testBuildsWithoutAModelLabelHaveNoLabelledTier(): void
    {
        self::assertNull(BuildFacts::fromLabels(['review:full'], [])->labelledTier);
    }

    public function testTheLastModelLabelWins(): void
    {
        self::assertSame(Tier::Light, BuildFacts::fromLabels(['model:strong', 'model:light'], [])->labelledTier);
    }

    public function testTheSetbackPassedByTheLoopIsKept(): void
    {
        self::assertSame(Setback::Stall, BuildFacts::fromLabels([], [], Setback::Stall)->setback);
    }

    public function testTheFactsHoldNoModelOrVendorOfTheJob(): void
    {
        $fields = array_map(static fn ($property): string => $property->getName(), new ReflectionClass(BuildFacts::class)->getProperties());

        self::assertSame(['labelledTier', 'paths', 'setback', 'escalation'], $fields);
    }
}
