<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\Domain;

use Intakt\Core\Build\Domain\BuildLimits;
use Intakt\Core\Build\Domain\BuildObservation;
use Intakt\Core\Build\Domain\BuildProgress;
use Intakt\Core\Build\Domain\BuildStopCause;
use Intakt\Core\Build\Domain\BuildStopRule;
use Intakt\Core\Build\Domain\BuildWatch;
use Intakt\Core\Build\Domain\TrustedBuildFigures;
use Intakt\Core\Build\Domain\UntrustedBuildFigures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(BuildObservation::class)]
#[CoversClass(TrustedBuildFigures::class)]
#[CoversClass(UntrustedBuildFigures::class)]
#[UsesClass(BuildProgress::class)]
#[UsesClass(BuildLimits::class)]
#[UsesClass(BuildStopRule::class)]
#[UsesClass(BuildWatch::class)]
final class BuildObservationTest extends TestCase
{
    public function testBothTokenFiguresComeFromTheTrustedPart(): void
    {
        $progress = $this->observation(1500, new UntrustedBuildFigures('a', [], false))->progress();

        self::assertSame([1500, 1500], [$progress->tokensUsed, $progress->taskTokensUsed]);
    }

    public function testTheBuildersFiguresReachTheProgressAsTheyAre(): void
    {
        $progress = $this->observation(1, new UntrustedBuildFigures('tips', ['testA' => 'failed'], true))->progress();

        self::assertSame(['tips', ['testA' => 'failed'], true], [$progress->contentFingerprint, $progress->failingTests, $progress->checkRunning]);
    }

    public function testForgedProgressFromTheBuilderNeverKeepsABuildPastTheCeiling(): void
    {
        $clock = new MockClock('2026-11-28 10:00:00 UTC');
        $rule = new BuildStopRule($clock);
        $watch = $rule->startWatching(new BuildLimits(10, 60, 100, 1000), $this->observation(50, new UntrustedBuildFigures('a', [], false))->progress());

        $watch = $rule->observe($watch, $this->observation(100, new UntrustedBuildFigures('forged', ['testB' => 'x'], true))->progress());

        self::assertSame(BuildStopCause::RunawayCeiling, $rule->stopCause($watch));
    }

    public function testForgedProgressFromTheBuilderNeverKeepsABuildPastTheTaskTotal(): void
    {
        $clock = new MockClock('2026-11-28 10:00:00 UTC');
        $rule = new BuildStopRule($clock);
        $watch = $rule->startWatching(new BuildLimits(10, 60, 100, 100), $this->observation(50, new UntrustedBuildFigures('a', [], false))->progress());

        $watch = $rule->observe($watch, $this->observation(100, new UntrustedBuildFigures('forged', [], true))->progress());

        self::assertSame(BuildStopCause::PerTaskTotal, $rule->stopCause($watch));
    }

    private function observation(int $tokens, UntrustedBuildFigures $untrusted): BuildObservation
    {
        return new BuildObservation(new TrustedBuildFigures($tokens, new MockClock('2026-11-28 10:00:00 UTC')->now()), $untrusted);
    }
}
