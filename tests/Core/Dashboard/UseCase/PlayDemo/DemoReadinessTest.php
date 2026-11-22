<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\PlayDemo;

use Intakt\Core\Dashboard\Domain\FirstReviewStep;
use Intakt\Core\Dashboard\Domain\FirstRun;
use Intakt\Core\Dashboard\Domain\ProjectStep;
use Intakt\Core\Dashboard\Domain\ProviderStep;
use Intakt\Core\Dashboard\UseCase\PlayDemo\DemoReadiness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DemoReadiness::class)]
#[UsesClass(FirstRun::class)]
#[UsesClass(ProviderStep::class)]
#[UsesClass(ProjectStep::class)]
#[UsesClass(FirstReviewStep::class)]
final class DemoReadinessTest extends TestCase
{
    /** @return iterable<string, array{DemoReadiness, FirstRun}> */
    public static function readinessAndPages(): iterable
    {
        foreach (DemoReadiness::cases() as $case) {
            yield $case->value.' without a key' => [$case, self::withoutAKey()];
            yield $case->value.' with a key and a sample to start' => [$case, self::withAKey(true)];
            yield $case->value.' with a key and nothing to start' => [$case, self::withAKey(false)];
        }
    }

    #[DataProvider('readinessAndPages')]
    public function testTheFirstRunLineNamesNoVariableFileOrCommand(DemoReadiness $readiness, FirstRun $firstRun): void
    {
        self::assertDoesNotMatchRegularExpression('/[A-Z]{2,}_[A-Z_]+|intakt:|\.ya?ml|docker|`/', $readiness->firstRunLine($firstRun));
    }

    public function testWithARecordingTheFirstRunLineSaysWhatThePlayButtonShows(): void
    {
        self::assertSame('Two AI labs read a change, then the owl decides what to fix.', DemoReadiness::Ready->firstRunLine(self::withoutAKey()));
    }

    public function testWithoutARecordingOrAKeyTheFirstRunLinePointsToTheKeyForm(): void
    {
        self::assertSame(DemoReadiness::NoRecording->firstRunLine(self::withoutAKey()), DemoReadiness::NotComposeDemo->firstRunLine(self::withoutAKey()));
        self::assertStringContainsString('Add a key below', DemoReadiness::NoRecording->firstRunLine(self::withoutAKey()));
    }

    public function testWithoutARecordingAndWithAKeyTheFirstRunLinePointsToTheCardThatStartsAReview(): void
    {
        self::assertSame('This install has no recorded review. A live review can be started or opened from the cards below.', DemoReadiness::NoRecording->firstRunLine(self::withAKey(true)));
        self::assertSame(DemoReadiness::NoRecording->firstRunLine(self::withAKey(true)), DemoReadiness::NotComposeDemo->firstRunLine(self::withAKey(true)));
    }

    public function testWithAKeyButNoCardThatStartsAReviewTheFirstRunLinePromisesNoStart(): void
    {
        self::assertSame('This install has no recorded review, and no card below can start a live one yet.', DemoReadiness::NoRecording->firstRunLine(self::withAKey(false)));
    }

    private static function withoutAKey(): FirstRun
    {
        return new FirstRun(new ProviderStep([], [], null, []), new ProjectStep([]), new FirstReviewStep(null, false));
    }

    private static function withAKey(bool $sampleCanStart): FirstRun
    {
        return new FirstRun(new ProviderStep(['anthropic'], [], null, []), new ProjectStep([]), new FirstReviewStep(null, $sampleCanStart));
    }
}
