<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\SameCause;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SameCause::class)]
final class SameCauseTest extends TestCase
{
    public function testALinkedFindingNamesItsCause(): void
    {
        self::assertSame('F1', new SameCause()->with('F3', 'F1')->causeOf('F3'));
    }

    public function testAFindingWithoutALinkHasNoCause(): void
    {
        self::assertNull(new SameCause()->with('F3', 'F1')->causeOf('F1'));
    }

    public function testAFindingNamedAsItsOwnCauseIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SameCause()->with('F1', 'F1');
    }

    public function testALinkWithoutAFindingIdIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SameCause()->with('', 'F1');
    }

    public function testALinkWithoutACauseIdIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SameCause()->with('F3', '');
    }

    public function testAFindingIdThatOnlyStartsLikeItsCauseIsAllowed(): void
    {
        self::assertSame('F1', new SameCause()->with('F10', 'F1')->causeOf('F10'));
    }

    public function testAddingALinkLeavesTheOriginalUnchanged(): void
    {
        $none = new SameCause();

        $none->with('F3', 'F1');

        self::assertSame([], iterator_to_array($none));
    }

    public function testALaterLinkOfTheSameFindingReplacesTheEarlierOne(): void
    {
        self::assertSame(['F3' => 'F2'], iterator_to_array(new SameCause()->with('F3', 'F1')->with('F3', 'F2')));
    }

    public function testTheLinksAreIteratedInTheOrderTheyWereAdded(): void
    {
        self::assertSame(['F3' => 'F1', 'F2' => 'F1'], iterator_to_array(new SameCause()->with('F3', 'F1')->with('F2', 'F1')));
    }

    public function testANumericFindingIdIsIteratedAsAString(): void
    {
        $links = [];

        foreach (new SameCause()->with('7', 'F1') as $finding => $cause) {
            $links[] = [$finding, $cause];
        }

        self::assertSame([['7', 'F1']], $links);
    }
}
