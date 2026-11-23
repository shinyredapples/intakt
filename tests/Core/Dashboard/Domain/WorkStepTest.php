<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use Intakt\Core\Dashboard\Domain\WorkStep;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkStep::class)]
final class WorkStepTest extends TestCase
{
    public function testTheStepsFollowThePathFromPlanToMerge(): void
    {
        self::assertSame([1, 2, 3, 4, 5], array_map(static fn (WorkStep $step): int => $step->position(), [WorkStep::Plan, WorkStep::Build, WorkStep::Check, WorkStep::Review, WorkStep::Merge]));
    }

    public function testAStepComesBeforeOnlyTheStepsLaterOnThePath(): void
    {
        self::assertTrue(WorkStep::Build->comesBefore(WorkStep::Review));
        self::assertFalse(WorkStep::Review->comesBefore(WorkStep::Review));
        self::assertFalse(WorkStep::Merge->comesBefore(WorkStep::Review));
    }

    public function testEachStepSaysWhatHappensOnItInOneWord(): void
    {
        self::assertSame(['Planning', 'Building', 'Checking', 'Reviewing', 'Merging'], array_map(static fn (WorkStep $step): string => $step->doing(), WorkStep::cases()));
    }
}
