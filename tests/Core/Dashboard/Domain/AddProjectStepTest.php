<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use Intakt\Core\Dashboard\Domain\AddProjectStep;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AddProjectStep::class)]
final class AddProjectStepTest extends TestCase
{
    /** @return iterable<string, array{string, AddProjectStep}> */
    public static function queries(): iterable
    {
        yield 'the first step' => ['1', AddProjectStep::Repository];
        yield 'the last step' => ['5', AddProjectStep::Start];
        yield 'zero' => ['0', AddProjectStep::Repository];
        yield 'one past the last' => ['6', AddProjectStep::Repository];
        yield 'negative' => ['-3', AddProjectStep::Repository];
        yield 'a leading zero' => ['03', AddProjectStep::Repository];
        yield 'a plus sign' => ['+2', AddProjectStep::Repository];
        yield 'a leading space' => [' 2', AddProjectStep::Repository];
        yield 'a trailing space' => ['2 ', AddProjectStep::Repository];
        yield 'a fraction' => ['2.5', AddProjectStep::Repository];
        yield 'a word' => ['three', AddProjectStep::Repository];
        yield 'empty' => ['', AddProjectStep::Repository];
    }

    #[DataProvider('queries')]
    public function testOnlyAStepsNumberOpensThatStepAndAnythingElseTheFirst(string $query, AddProjectStep $step): void
    {
        self::assertSame($step, AddProjectStep::fromQuery($query));
    }

    public function testTheCountNamesTheStepAmongAllSteps(): void
    {
        self::assertSame('Step 3 of 5: Check', AddProjectStep::Check->count());
    }

    public function testTheLabelsAreTheMocksStepNamesInOrder(): void
    {
        self::assertSame(
            ['Repository', 'Install the App', 'Check', 'Share and label', 'Start'],
            array_map(static fn (AddProjectStep $step): string => $step->label(), AddProjectStep::cases()),
        );
    }

    public function testTheStepsBeforeTheShownOneAreDoneAndTheLaterOnesUpcoming(): void
    {
        self::assertSame(
            ['done', 'done', 'current', 'upcoming', 'upcoming'],
            array_map(static fn (AddProjectStep $step): string => $step->markerAt(AddProjectStep::Check), AddProjectStep::cases()),
        );
    }

    public function testTheFirstStepHasNoStepBefore(): void
    {
        self::assertNull(AddProjectStep::Repository->previous());
    }

    public function testTheLastStepHasNoStepAfter(): void
    {
        self::assertNull(AddProjectStep::Start->next());
    }

    public function testAMiddleStepLeadsToItsNeighbors(): void
    {
        self::assertSame([AddProjectStep::InstallTheApp, AddProjectStep::ShareAndLabel], [AddProjectStep::Check->previous(), AddProjectStep::Check->next()]);
    }
}
