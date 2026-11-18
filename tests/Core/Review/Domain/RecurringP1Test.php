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
        $earlier = $this->finding(Severity::P1, 'src/Thing.php', 'Thing::save');
        $current = $this->finding(Severity::P1, 'src/Thing.php', 'Thing::save')->withId('F4');

        self::assertSame([[$current, $earlier]], new RecurringP1()->in([$earlier], [$current]));
    }

    public function testAP1AtAnotherLineOfTheSameFileAndSymbolIsStillRecurring(): void
    {
        $earlier = new Finding('F1', Severity::P1, 'src/Thing.php', 12, 'Thing::save', 'Not restart safe.', FixableBy::Builder, false, null, null);
        $current = new Finding('F2', Severity::P1, 'src/Thing.php', 40, 'Thing::save', 'Not restart safe.', FixableBy::Builder, false, null, null);

        self::assertCount(1, new RecurringP1()->in([$earlier], [$current]));
    }

    public function testAFileLevelP1AndAP1AtASymbolOfTheSameFileAreNotInTheSamePlace(): void
    {
        $earlier = $this->finding(Severity::P1, 'src/Thing.php', null);
        $current = $this->finding(Severity::P1, 'src/Thing.php', 'Thing::save');

        self::assertSame([], new RecurringP1()->in([$earlier], [$current]));
    }

    public function testTwoFileLevelP1sInTheSameFileAreInTheSamePlace(): void
    {
        $earlier = $this->finding(Severity::P1, 'src/Thing.php', null);
        $current = $this->finding(Severity::P1, 'src/Thing.php', null);

        self::assertCount(1, new RecurringP1()->in([$earlier], [$current]));
    }

    public function testTwoP1sAtOnePlaceOfTheCurrentHeadRecurOnce(): void
    {
        $earlier = $this->finding(Severity::P1, 'src/Thing.php', 'Thing::save');
        $current = $this->finding(Severity::P1, 'src/Thing.php', 'Thing::save')->withId('F4');

        self::assertSame([[$current, $earlier]], new RecurringP1()->in([$earlier], [$current, $current->withId('F5')]));
    }

    public function testAFileAndSymbolCannotShiftIntoAnotherPlaceOfTheSameCharacters(): void
    {
        self::assertNotSame(new RecurringP1()->placeOf($this->finding(Severity::P1, 'a', 'b::c')), new RecurringP1()->placeOf($this->finding(Severity::P1, 'a"', 'b::c')));
        self::assertNull(new RecurringP1()->placeOf($this->finding(Severity::P1, null, 'Thing::save')));
    }

    public function testAP1AtAnotherSymbolOfTheSameFileIsNotRecurring(): void
    {
        $earlier = $this->finding(Severity::P1, 'src/Thing.php', 'Thing::save');
        $current = $this->finding(Severity::P1, 'src/Thing.php', 'Thing::load');

        self::assertSame([], new RecurringP1()->in([$earlier], [$current]));
    }

    public function testAP1InAnotherFileIsNotRecurring(): void
    {
        $earlier = $this->finding(Severity::P1, 'src/Thing.php', null);
        $current = $this->finding(Severity::P1, 'src/Other.php', null);

        self::assertSame([], new RecurringP1()->in([$earlier], [$current]));
    }

    public function testAP2FlaggedInTheSamePlaceTwiceIsNotARecurringP1(): void
    {
        $earlier = $this->finding(Severity::P2, 'src/Thing.php', null);
        $current = $this->finding(Severity::P2, 'src/Thing.php', null);

        self::assertSame([], new RecurringP1()->in([$earlier], [$current]));
    }

    public function testAP1WhereTheEarlierHeadHadOnlyAP2IsNotRecurring(): void
    {
        $earlier = $this->finding(Severity::P2, 'src/Thing.php', null);
        $current = $this->finding(Severity::P1, 'src/Thing.php', null);

        self::assertSame([], new RecurringP1()->in([$earlier], [$current]));
    }

    public function testTwoP1sWithoutAFileAreNeverInTheSamePlace(): void
    {
        $earlier = $this->finding(Severity::P1, null, null);
        $current = $this->finding(Severity::P1, null, null);

        self::assertSame([], new RecurringP1()->in([$earlier], [$current]));
    }

    public function testAP1WithNoEarlierHeadIsNeverRecurring(): void
    {
        self::assertSame([], new RecurringP1()->in([], [$this->finding(Severity::P1, 'src/Thing.php', null)]));
    }

    private function finding(Severity $severity, ?string $file, ?string $symbol): Finding
    {
        return new Finding('F1', $severity, $file, null === $file ? null : 3, $symbol, 'The save is not restart safe.', FixableBy::Builder, false, null, null);
    }
}
