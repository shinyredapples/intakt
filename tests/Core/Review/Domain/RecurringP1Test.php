<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Domain;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Domain\RecurringP1;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecurringP1::class)]
#[UsesClass(Finding::class)]
final class RecurringP1Test extends TestCase
{
    public function testAP1FlaggedAtTheSameFileAndSymbolOnTheNextHeadIsRecurring(): void
    {
        $earlier = $this->finding('F1', Severity::P1, 'src/Thing.php', 'Thing::save');
        $current = $this->finding('F4', Severity::P1, 'src/Thing.php', 'Thing::save');

        self::assertSame([[$current, $earlier]], new RecurringP1()->in([$earlier], [$current]));
    }

    public function testAP1AtAnotherLineOfTheSameFileAndSymbolIsStillRecurring(): void
    {
        $earlier = $this->finding('F1', Severity::P1, 'src/Thing.php', 'Thing::save', 12);
        $current = $this->finding('F2', Severity::P1, 'src/Thing.php', 'Thing::save', 40);

        self::assertCount(1, new RecurringP1()->in([$earlier], [$current]));
    }

    public function testAP1InTheSameFileWhereEitherNamesNoSymbolIsRecurring(): void
    {
        $earlier = $this->finding('F1', Severity::P1, 'src/Thing.php', null);
        $current = $this->finding('F2', Severity::P1, 'src/Thing.php', 'Thing::save');

        self::assertCount(1, new RecurringP1()->in([$earlier], [$current]));
    }

    public function testAP1AtAnotherSymbolOfTheSameFileIsNotRecurring(): void
    {
        $earlier = $this->finding('F1', Severity::P1, 'src/Thing.php', 'Thing::save');
        $current = $this->finding('F2', Severity::P1, 'src/Thing.php', 'Thing::load');

        self::assertSame([], new RecurringP1()->in([$earlier], [$current]));
    }

    public function testAP1InAnotherFileIsNotRecurring(): void
    {
        $earlier = $this->finding('F1', Severity::P1, 'src/Thing.php', null);
        $current = $this->finding('F2', Severity::P1, 'src/Other.php', null);

        self::assertSame([], new RecurringP1()->in([$earlier], [$current]));
    }

    public function testAP2FlaggedInTheSamePlaceTwiceIsNotARecurringP1(): void
    {
        $earlier = $this->finding('F1', Severity::P2, 'src/Thing.php', null);
        $current = $this->finding('F2', Severity::P2, 'src/Thing.php', null);

        self::assertSame([], new RecurringP1()->in([$earlier], [$current]));
    }

    public function testAP1WhereTheEarlierHeadHadOnlyAP2IsNotRecurring(): void
    {
        $earlier = $this->finding('F1', Severity::P2, 'src/Thing.php', null);
        $current = $this->finding('F2', Severity::P1, 'src/Thing.php', null);

        self::assertSame([], new RecurringP1()->in([$earlier], [$current]));
    }

    public function testTwoP1sWithoutAFileAreNeverInTheSamePlace(): void
    {
        $earlier = $this->finding('F1', Severity::P1, null, null);
        $current = $this->finding('F2', Severity::P1, null, null);

        self::assertSame([], new RecurringP1()->in([$earlier], [$current]));
    }

    public function testAP1WithNoEarlierHeadIsNeverRecurring(): void
    {
        self::assertSame([], new RecurringP1()->in([], [$this->finding('F1', Severity::P1, 'src/Thing.php', null)]));
    }

    private function finding(string $id, Severity $severity, ?string $file, ?string $symbol, int $line = 3): Finding
    {
        return new Finding($id, $severity, $file, null === $file ? null : $line, $symbol, 'The save is not restart safe.', FixableBy::Builder, false, null, null);
    }
}
