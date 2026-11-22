<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\ModelPrice;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModelPrice::class)]
final class ModelPriceTest extends TestCase
{
    public function testTheAmountsIterateByKindInTheOrderTheyWereGiven(): void
    {
        self::assertSame(['output' => 2, 'input' => 0.5], iterator_to_array(ModelPrice::none()->with('output', 2)->with('input', 0.5)));
    }

    public function testAKindGivenAgainKeepsItsLatestAmount(): void
    {
        self::assertSame(['input' => 0.75], iterator_to_array(ModelPrice::none()->with('input', 0.5)->with('input', 0.75)));
    }

    public function testAPriceWithoutAmountsIteratesNothing(): void
    {
        self::assertSame([], iterator_to_array(ModelPrice::none()));
    }
}
