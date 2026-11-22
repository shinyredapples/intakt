<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\PlayDemo;

use Intakt\Core\Dashboard\Domain\ProviderStep;
use Intakt\Core\Dashboard\UseCase\PlayDemo\DemoReadiness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DemoReadiness::class)]
#[UsesClass(ProviderStep::class)]
final class DemoReadinessTest extends TestCase
{
    /** @return iterable<string, array{DemoReadiness, ProviderStep}> */
    public static function readinessAndKeys(): iterable
    {
        foreach (DemoReadiness::cases() as $case) {
            yield $case->value.' without a key' => [$case, self::withoutAKey()];
            yield $case->value.' with a key' => [$case, self::withAKey()];
        }
    }

    #[DataProvider('readinessAndKeys')]
    public function testTheFirstRunLineNamesNoVariableFileOrCommand(DemoReadiness $readiness, ProviderStep $providers): void
    {
        self::assertDoesNotMatchRegularExpression('/[A-Z]{2,}_[A-Z_]+|intakt:|\.ya?ml|docker|`/', $readiness->firstRunLine($providers));
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

    public function testWithoutARecordingAndWithAKeyTheFirstRunLinePointsToTheCardsInstead(): void
    {
        self::assertSame('This install has no recorded review. A live review can be started from the cards below.', DemoReadiness::NoRecording->firstRunLine(self::withAKey()));
        self::assertSame(DemoReadiness::NoRecording->firstRunLine(self::withAKey()), DemoReadiness::NotComposeDemo->firstRunLine(self::withAKey()));
    }

    private static function withoutAKey(): ProviderStep
    {
        return new ProviderStep([], [], null, []);
    }

    private static function withAKey(): ProviderStep
    {
        return new ProviderStep(['anthropic'], [], null, []);
    }
}
