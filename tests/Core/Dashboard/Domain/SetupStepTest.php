<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use Intakt\Core\Dashboard\Domain\SetupStep;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SetupStep::class)]
final class SetupStepTest extends TestCase
{
    /** @return iterable<string, array{string, SetupStep}> */
    public static function queries(): iterable
    {
        yield 'the first step' => ['1', SetupStep::Repository];
        yield 'the last step' => ['5', SetupStep::Ready];
        yield 'zero' => ['0', SetupStep::Repository];
        yield 'one past the last' => ['6', SetupStep::Repository];
        yield 'negative' => ['-3', SetupStep::Repository];
        yield 'a leading zero' => ['03', SetupStep::Repository];
        yield 'a fraction' => ['2.5', SetupStep::Repository];
        yield 'a word' => ['three', SetupStep::Repository];
        yield 'empty' => ['', SetupStep::Repository];
        yield 'a number past the integers' => ['99999999999999999999', SetupStep::Repository];
    }

    #[DataProvider('queries')]
    public function testOnlyAStepsNumberOpensThatStepAndAnythingElseTheFirst(string $query, SetupStep $step): void
    {
        self::assertSame($step, SetupStep::fromQuery($query));
    }

    public function testTheCountNamesTheStepAmongAllSteps(): void
    {
        self::assertSame('Step 3 of 5: AI account', SetupStep::AiAccount->count());
    }

    public function testTheFirstStepHasNoStepBefore(): void
    {
        self::assertNull(SetupStep::Repository->previous());
        self::assertSame(SetupStep::Repository, SetupStep::GitHubApp->previous());
    }

    public function testTheLastStepHasNoStepAfter(): void
    {
        self::assertNull(SetupStep::Ready->next());
        self::assertSame(SetupStep::Ready, SetupStep::Check->next());
    }

    public function testTheStepsBeforeTheShownOneAreDoneAndTheOnesAfterAreUpcoming(): void
    {
        self::assertSame(
            ['done', 'done', 'current', 'upcoming', 'upcoming'],
            array_map(static fn (SetupStep $marker): string => $marker->markerAt(SetupStep::AiAccount), SetupStep::cases()),
        );
    }
}
