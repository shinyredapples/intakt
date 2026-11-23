<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\Contract;

use Intakt\Core\Watcher\Contract\EligibilityLabels;
use Intakt\Core\Watcher\Contract\ProjectNamesNoEligibilityLabel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EligibilityLabels::class)]
#[CoversClass(ProjectNamesNoEligibilityLabel::class)]
final class EligibilityLabelsTest extends TestCase
{
    public function testEachProjectHasTheLabelItsWatchSectionNames(): void
    {
        $labels = new EligibilityLabels(['alpha' => ['eligibility_label' => 'intakt'], 'beta' => ['eligibility_label' => 'build-me']]);

        self::assertSame(['intakt', 'build-me'], [$labels->of('alpha'), $labels->of('beta')]);
    }

    public function testAProjectWithoutAWatchSectionNamesNoEligibilityLabel(): void
    {
        $this->expectException(ProjectNamesNoEligibilityLabel::class);
        $this->expectExceptionMessage('Project "alpha" has no watch section, so it names no eligibility label.');

        new EligibilityLabels(['beta' => ['eligibility_label' => 'intakt']])->of('alpha');
    }
}
