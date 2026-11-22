<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\Contract;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Build\Contract\BuildFacts;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Review\Contract\Labels;
use Intakt\Core\Build\Contract\Setback;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(BuildFacts::class)]
final class BuildFactsTest extends TestCase
{
    public function testAModelLabelNamesTheTier(): void
    {
        $facts = BuildFacts::fromLabels(new Labels('priority:high', 'model:strong'), new Paths('a.php'));

        self::assertSame(Tier::Strong, $facts->labelledTier);
        self::assertSame(['a.php'], [...$facts->paths]);
    }

    public function testAModelLabelNamingAModelInsteadOfATierIsIgnored(): void
    {
        self::assertNull(BuildFacts::fromLabels(new Labels('model:claude-opus-5-5'), new Paths())->labelledTier);
    }

    public function testBuildsWithoutAModelLabelHaveNoLabelledTier(): void
    {
        self::assertNull(BuildFacts::fromLabels(new Labels('review:full'), new Paths())->labelledTier);
    }

    public function testTheLastModelLabelWins(): void
    {
        self::assertSame(Tier::Light, BuildFacts::fromLabels(new Labels('model:strong', 'model:light'), new Paths())->labelledTier);
    }

    public function testTheSetbackPassedByTheLoopIsKept(): void
    {
        self::assertSame(Setback::Stall, BuildFacts::fromLabels(new Labels(), new Paths(), Setback::Stall)->setback);
    }

    public function testTheFactsHoldNoModelOrVendorOfTheJob(): void
    {
        $fields = array_map(static fn ($property): string => $property->getName(), new ReflectionClass(BuildFacts::class)->getProperties());

        self::assertSame(['labelledTier', 'paths', 'setback'], $fields);
    }
}
