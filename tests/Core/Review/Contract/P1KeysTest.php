<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\P1Keys;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(P1Keys::class)]
final class P1KeysTest extends TestCase
{
    public function testAFindingKeepsTheKeyItWasGiven(): void
    {
        self::assertSame('abc', new P1Keys()->with('F1', 'abc')->keyOf('F1'));
    }

    public function testAFindingGivenNoKeyHasNone(): void
    {
        self::assertNull(new P1Keys()->with('F1', null)->keyOf('F1'));
    }

    public function testAFindingNotListedHasNoKey(): void
    {
        self::assertNull(new P1Keys()->with('F1', 'abc')->keyOf('F2'));
    }

    public function testAddingAKeyLeavesTheOriginalUnchanged(): void
    {
        $none = new P1Keys();

        $none->with('F1', 'abc');

        self::assertSame([], iterator_to_array($none));
    }

    public function testTheKeysAreListedInTheOrderTheyWereGivenWithTheFindingsThatGetNone(): void
    {
        self::assertSame(['F2' => 'abc', 'F1' => null], iterator_to_array(new P1Keys()->with('F2', 'abc')->with('F1', null)));
    }

    public function testANumericFindingIdStaysAStringKey(): void
    {
        self::assertSame(['7'], $this->idsOf(new P1Keys()->with('7', 'abc')));
    }

    /**
     * @return list<string>
     */
    private function idsOf(P1Keys $keys): array
    {
        $ids = [];

        foreach ($keys as $id => $key) {
            $ids[] = $id;
        }

        return $ids;
    }
}
