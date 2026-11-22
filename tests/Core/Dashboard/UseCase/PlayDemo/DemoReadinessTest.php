<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\PlayDemo;

use Intakt\Core\Dashboard\UseCase\PlayDemo\DemoReadiness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DemoReadiness::class)]
final class DemoReadinessTest extends TestCase
{
    /** @return iterable<string, array{DemoReadiness}> */
    public static function readiness(): iterable
    {
        foreach (DemoReadiness::cases() as $case) {
            yield $case->value => [$case];
        }
    }

    #[DataProvider('readiness')]
    public function testTheFirstRunLineNamesNoVariableFileOrCommand(DemoReadiness $readiness): void
    {
        self::assertDoesNotMatchRegularExpression('/[A-Z]{2,}_[A-Z_]+|intakt:|\.ya?ml|docker|`/', $readiness->firstRunLine());
    }

    public function testWithARecordingTheFirstRunLineSaysWhatThePlayButtonShows(): void
    {
        self::assertSame('Two AI labs read a change, then the owl decides what to fix.', DemoReadiness::Ready->firstRunLine());
    }

    public function testWithoutARecordingTheFirstRunLinePointsToTheKeyStep(): void
    {
        self::assertSame(DemoReadiness::NoRecording->firstRunLine(), DemoReadiness::NotComposeDemo->firstRunLine());
        self::assertStringContainsString('Add a key below', DemoReadiness::NoRecording->firstRunLine());
    }
}
