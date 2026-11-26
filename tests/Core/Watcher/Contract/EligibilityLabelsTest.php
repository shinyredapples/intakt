<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\Contract;

use Intakt\Core\Watcher\Contract\EligibilityLabels;
use Intakt\Core\Watcher\Contract\ProjectEligibilityLabel;
use Intakt\Core\Watcher\Contract\ProjectNamesNoEligibilityLabel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EligibilityLabels::class)]
#[CoversClass(ProjectEligibilityLabel::class)]
#[CoversClass(ProjectNamesNoEligibilityLabel::class)]
final class EligibilityLabelsTest extends TestCase
{
    public function testEachProjectHasTheLabelItsWatchSectionNames(): void
    {
        $labels = new EligibilityLabels(new ProjectEligibilityLabel('alpha', 'intakt'), new ProjectEligibilityLabel('beta', 'build-me'));

        self::assertSame(['intakt', 'build-me'], [$labels->of('alpha'), $labels->of('beta')]);
    }

    public function testAProjectWithoutAWatchSectionNamesNoEligibilityLabel(): void
    {
        $this->expectExceptionObject(ProjectNamesNoEligibilityLabel::named('alpha'));

        new EligibilityLabels(new ProjectEligibilityLabel('beta', 'intakt'))->of('alpha');
    }

    public function testAProjectNamedLikeANumberKeepsItsLabel(): void
    {
        self::assertSame('build', new EligibilityLabels(new ProjectEligibilityLabel('42', 'build'))->of('42'));
    }
}
