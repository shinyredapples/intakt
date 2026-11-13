<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\Infrastructure;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Build\Contract\BuildFacts;
use Intakt\Core\Build\Contract\BuildRoutingNotConfigured;
use Intakt\Core\Build\Contract\TierChoice;
use Intakt\Core\Build\Contract\TierReason;
use Intakt\Core\Build\Domain\ModelRouting;
use Intakt\Core\Build\Domain\RoutingRules;
use Intakt\Core\Build\Infrastructure\ConfiguredBuildRouting;
use Intakt\Core\Review\Contract\PathGlob;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfiguredBuildRouting::class)]
#[CoversClass(BuildRoutingNotConfigured::class)]
#[UsesClass(ModelRouting::class)]
#[UsesClass(RoutingRules::class)]
#[UsesClass(BuildFacts::class)]
#[UsesClass(TierChoice::class)]
#[UsesClass(PathGlob::class)]
final class ConfiguredBuildRoutingTest extends TestCase
{
    public function testAProjectIsRoutedByItsOwnRules(): void
    {
        $choice = $this->routing()->tierFor('alpha', new BuildFacts(null, ['src/Security/a.php']));

        self::assertSame(Tier::Strong, $choice->tier);
        self::assertSame(TierReason::SensitivePath, $choice->reason);
    }

    public function testAnotherProjectKeepsItsOwnDefaultTier(): void
    {
        $choice = $this->routing()->tierFor('beta', new BuildFacts(null, ['src/Security/a.php']));

        self::assertSame(Tier::Light, $choice->tier);
    }

    public function testAnUnknownProjectIsRefused(): void
    {
        $this->expectException(BuildRoutingNotConfigured::class);

        $this->routing()->tierFor('gamma', new BuildFacts(null, []));
    }

    private function routing(): ConfiguredBuildRouting
    {
        return new ConfiguredBuildRouting([
            'alpha' => ['tier' => 'standard', 'sensitive_paths' => ['src/**/Security/**']],
            'beta' => ['tier' => 'light', 'sensitive_paths' => []],
        ], new ModelRouting());
    }
}
