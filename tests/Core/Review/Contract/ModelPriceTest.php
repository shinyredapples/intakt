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

    public function testANumericKindGivenAgainKeepsItsNameItsPlaceAndItsLatestAmount(): void
    {
        $price = ModelPrice::none()->with('42', 1)->with('output', 2)->with('42', 3);

        self::assertSame([['42', 3], ['output', 2]], $this->pairsOf($price));
    }

    public function testNumericKindsKeepTheirNames(): void
    {
        self::assertSame([['1', 0.5], ['2', 1]], $this->pairsOf(ModelPrice::none()->with('1', 0.5)->with('2', 1)));
    }

    public function testAPriceWithoutAmountsIteratesNothing(): void
    {
        self::assertSame([], iterator_to_array(ModelPrice::none()));
    }

    /**
     * @return list<array{mixed, int|float}>
     */
    private function pairsOf(ModelPrice $price): array
    {
        $pairs = [];

        foreach ($price as $kind => $amount) {
            $pairs[] = [$kind, $amount];
        }

        return $pairs;
    }
}
