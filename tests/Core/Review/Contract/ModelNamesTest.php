<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\ModelNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModelNames::class)]
final class ModelNamesTest extends TestCase
{
    public function testTheNamesIterateInTheOrderTheyWereGiven(): void
    {
        self::assertSame(['small', 'big'], [...new ModelNames('small', 'big')]);
    }

    public function testNamesSpreadFromAStringKeyedArrayIterateAsAList(): void
    {
        self::assertSame(['big', 'small'], [...new ModelNames(...['first' => 'big', 'second' => 'small'])]);
    }

    public function testNoNamesIterateAsNothing(): void
    {
        self::assertSame([], [...new ModelNames()]);
    }

    public function testOnlyNoNamesAreEmpty(): void
    {
        self::assertSame([true, false], [new ModelNames()->isEmpty(), new ModelNames('small')->isEmpty()]);
    }
}
